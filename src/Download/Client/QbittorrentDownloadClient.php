<?php

declare(strict_types=1);

namespace App\Download\Client;

use App\Entity\Integration;
use App\Search\Source\ReleaseCandidate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * qBittorrent WebUI (v2 API) download client for the torrent protocol. Unlike the
 * synchronous HttpDownloadClient, a torrent download is asynchronous: addDownload()
 * submits the magnet/URL and returns immediately with the torrent hash, and the
 * torrent poller later calls getStatus() until the torrent finishes seeding.
 *
 * Connection (base URL + credentials) comes from the `qbittorrent` Integration
 * row. Two auth modes:
 *  - AUTH_BASIC: username/password → cookie/SID login. The login cookie is
 *    fetched lazily and cached for this instance's lifetime (one worker
 *    invocation).
 *  - AUTH_API_KEY: qBittorrent's native stateless API key (≥ v5.2.0 / WebAPI
 *    2.14.1), sent as `Authorization: Bearer <key>` on every request. API keys
 *    are rejected on the /auth/login and /auth/logout endpoints, so the SID
 *    flow is skipped entirely in this mode.
 *
 * The dispatcher auto-selects this client because getProtocol() returns the
 * torrent protocol (see config/services.yaml `app.download_client` tag).
 */
final class QbittorrentDownloadClient implements DownloadClientInterface
{
    private const TIMEOUT_SECONDS = 30;

    /** qBittorrent states that mean "still fetching bytes". */
    private const DOWNLOADING_STATES = [
        'downloading', 'metaDL', 'stalledDL', 'queuedDL', 'forcedDL',
        'allocating', 'checkingDL', 'checkingResumeData', 'moving',
    ];

    /** States that mean "download finished" (now seeding / checked / paused-after-complete). */
    private const SEEDING_STATES = [
        'uploading', 'stalledUP', 'queuedUP', 'forcedUP', 'pausedUP', 'checkingUP',
    ];

    private const ERROR_STATES = ['error', 'missingFiles'];

    /** Prefix for the unique throwaway tag used to identify a torrent we just added. */
    private const ADD_TAG_PREFIX = 'spinescout-add-';

    /**
     * How long to wait for a non-magnet add (e.g. a Prowlarr /download proxy URL) to
     * register in the client. qBittorrent has to fetch the link and resolve the
     * torrent/magnet metadata first, which can take many seconds — far longer than a
     * magnet, whose hash is known up front. 30 × 1s = 30s.
     */
    private const ADD_RESOLVE_ATTEMPTS = 30;
    private const ADD_RESOLVE_INTERVAL_US = 1_000_000;

    private ?string $sidCookie = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TorrentClientSettings $integrations,
        private readonly LoggerInterface $logger,
        private readonly int $addResolveAttempts = self::ADD_RESOLVE_ATTEMPTS,
        private readonly int $addResolveIntervalUs = self::ADD_RESOLVE_INTERVAL_US,
    ) {
    }

    public function getName(): string
    {
        return 'qbittorrent';
    }

    public function getProtocol(): string
    {
        return ReleaseCandidate::PROTOCOL_TORRENT;
    }

    public function isConfigured(): bool
    {
        $row = $this->integrations->qbittorrentIntegration();

        return $row !== null
            && $row->isEnabled()
            && $row->getBaseUrl() !== null && $row->getBaseUrl() !== '';
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function testConnection(): array
    {
        $row = $this->integrations->qbittorrentIntegration();
        if ($row === null || $row->getBaseUrl() === null || $row->getBaseUrl() === '') {
            return [false, 'Download client URL is not set.'];
        }

        try {
            $sid = $this->login($row);
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . '/api/v2/app/version', [
                'headers' => $this->authHeaders($row, $sid),
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            if ($response->getStatusCode() !== 200) {
                return [false, 'Download client returned HTTP ' . $response->getStatusCode() . ' (check credentials).'];
            }

            return [true, 'Connected to download client ' . trim($response->getContent()) . '.'];
        } catch (HttpExceptionInterface $e) {
            // Covers TransportExceptionInterface too (network failures) — this must
            // stay before the \RuntimeException catch, since Symfony's concrete
            // TransportException extends \RuntimeException.
            return [false, 'Connection failed: ' . $e->getMessage()];
        } catch (\RuntimeException $e) {
            // login() failures — the message is already user-facing. testConnection()
            // must never throw (see DownloadClientInterface::testConnection).
            return [false, $e->getMessage()];
        }
    }

    /**
     * Submit a magnet/URL to qBittorrent and return the torrent hash (the native
     * id used by getStatus / cancel).
     *
     * For a magnet the hash is read straight from the link. For anything else (a
     * .torrent URL, or a Prowlarr /download proxy URL that qBittorrent must fetch and
     * resolve asynchronously) the link carries no hash, so we tag the add with a
     * unique throwaway token and then poll for the torrent carrying that tag. The tag
     * makes resolution race-free even when many adds land in the same category at
     * once — unlike the old before/after category diff, which could attribute another
     * concurrent add's hash to this job, or time out (in ~2s) before a proxied torrent
     * had even registered, leaving the torrent downloading untracked.
     *
     * Response handling covers both API generations. WebAPI < 2.14 answers 200 with
     * a plain-text "Ok."/"Fails." body. WebAPI 2.14+ (qBittorrent ≥ 5.2) answers
     * 200 — or 202 while the add is still pending (the client fetches a non-magnet
     * URL asynchronously) — with a JSON body carrying success_count/failure_count/
     * pending_count/added_torrent_ids, and 409 when nothing was added at all
     * (every URL failed or the torrent is a duplicate). The "fail" substring check
     * must only ever run on the plain-text shape: the JSON always contains
     * "failure_count", which would otherwise reject every successful add.
     *
     * When $options['fileContents'] carries raw .torrent bytes (a private tracker
     * whose download URLs are session-authenticated, so qBittorrent could never
     * fetch them itself — the MAM grab), the add is POSTed as multipart with a
     * `torrents` file part instead of `urls`. A successful upload shares the same
     * tag-and-poll resolution as any other non-magnet add (the client's own id is
     * authoritative), but on a 409 the v1 info-hash is computed from the file
     * bytes so an already-present torrent re-links instead of failing the job —
     * the same recovery a magnet gets for free. The computed hash is only trusted
     * after the client confirms a torrent with that hash actually exists (a
     * v2-only torrent is indexed under a different id, and a 409 can also mean
     * the add itself failed).
     *
     * @param array<string, mixed> $options
     */
    public function addDownload(string $url, string $name, array $options = []): string
    {
        $row = $this->requireRow();
        $config = $this->integrations->getTorrentClientConfig();
        $sid = $this->login($row);

        $fileContents = is_string($options['fileContents'] ?? null) && $options['fileContents'] !== ''
            ? $options['fileContents']
            : null;

        // Magnets carry the hash in the link — no polling needed. A file upload
        // always resolves via the tag, whatever the accompanying URL looks like.
        $hash = $fileContents === null ? $this->extractInfoHash($url) : null;
        $tag = $hash === null ? self::ADD_TAG_PREFIX . bin2hex(random_bytes(8)) : null;

        $payload = ['category' => $config->category];
        if ($tag !== null) {
            $this->createTag($row, $sid, $tag);
            $payload['tags'] = $tag;
        }

        if ($fileContents !== null) {
            $payload['torrents'] = new DataPart($fileContents, self::torrentFilename($name), 'application/x-bittorrent');
            $form = new FormDataPart($payload);
            $requestOptions = [
                // The prepared multipart Content-Type (with its boundary) rides along
                // as "Name: value" lines, which Symfony's HttpClient accepts mixed
                // with the associative auth headers.
                'headers' => array_merge($this->authHeaders($row, $sid), $form->getPreparedHeaders()->toArray()),
                'body'    => $form->bodyToIterable(),
            ];
        } else {
            $payload['urls'] = $url;
            $requestOptions = [
                'headers' => $this->authHeaders($row, $sid),
                'body'    => $payload,
            ];
        }

        $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/add', $requestOptions + [
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        $status = $response->getStatusCode();
        $body = trim($response->getContent(false));

        if ($status < 200 || $status >= 300) {
            if ($status === 409) {
                // WebAPI 2.14+: 409 means nothing was added — the torrent is
                // already in the client, or every URL failed. A magnet carries
                // its hash, so re-link to the existing torrent instead of
                // failing a job whose torrent is in fact present. A file upload
                // carries its hash too (inside the bencoded info dict) — compute
                // it and re-link the same way, but only once the client confirms
                // a torrent under that hash really exists.
                if ($hash === null && $fileContents !== null) {
                    $computed = self::infoHashFromTorrentFile($fileContents);
                    if ($computed !== null && $this->torrentExists($row, $sid, $computed)) {
                        $hash = $computed;
                    }
                }
                if ($tag !== null) {
                    $this->deleteTag($row, $sid, $tag);
                }
                if ($hash !== null) {
                    return $hash;
                }
                throw new \RuntimeException('The download client reports this torrent is already added or could not be added (409 ' . $body . ').');
            }
            throw new \RuntimeException('The download client rejected the torrent add (' . $status . ' ' . $body . ').');
        }

        $result = json_decode($body, true);
        if (is_array($result)
            && (isset($result['success_count']) || isset($result['pending_count']) || isset($result['added_torrent_ids']))) {
            // WebAPI 2.14+ JSON shape.
            $ids = is_array($result['added_torrent_ids'] ?? null) ? $result['added_torrent_ids'] : [];
            $first = $ids[0] ?? null;
            if (is_string($first) && preg_match('/^[0-9a-fA-F]{40}$/', $first) === 1) {
                if ($tag !== null) {
                    $this->deleteTag($row, $sid, $tag);
                }

                return strtolower($first);
            }
            if ((int) ($result['pending_count'] ?? 0) <= 0 && (int) ($result['success_count'] ?? 0) <= 0) {
                // Defensive — a fully rejected add normally arrives as 409, not 2xx.
                throw new \RuntimeException('The download client rejected the torrent add (' . $status . ' ' . $body . ').');
            }
            // Added or pending without an id yet — resolve the hash below.
        } elseif (stripos($body, 'fail') !== false) {
            // Legacy plain-text shape only ("Ok."/"Fails.") — see the docblock.
            throw new \RuntimeException('The download client rejected the torrent add (' . $status . ' ' . $body . ').');
        }

        if ($hash !== null) {
            return $hash;
        }

        // Non-magnet: poll for the torrent carrying our unique tag.
        for ($attempt = 0; $attempt < $this->addResolveAttempts; ++$attempt) {
            usleep($this->addResolveIntervalUs);
            $found = $this->findHashByTag($row, $sid, $config->category, (string) $tag);
            if ($found !== null) {
                $this->deleteTag($row, $sid, (string) $tag);

                return $found;
            }
        }

        // Resolution failed. The client may still have accepted the add and be
        // fetching it untracked — remove anything carrying our tag (with its files) so
        // a retry starts clean instead of orphaning a download in the client.
        $this->cleanupByTag($row, $sid, $config->category, (string) $tag);

        throw new \RuntimeException('Torrent added but its hash could not be resolved from the download client.');
    }

    public function getStatus(string $downloadId): DownloadStatus
    {
        $row = $this->integrations->qbittorrentIntegration();
        if ($row === null) {
            return DownloadStatus::error('Download client is not configured.');
        }

        try {
            $sid = $this->login($row);
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . '/api/v2/torrents/info', [
                'headers' => $this->authHeaders($row, $sid),
                'query'   => ['hashes' => strtolower($downloadId)],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $rows = $response->toArray(false);
        } catch (HttpExceptionInterface | \JsonException | \RuntimeException $e) {
            // The client couldn't be reached/authenticated at all — this says nothing
            // about whether the torrent still exists, so it must NOT be treated as a
            // removal signal (that would orphan a torrent that's still downloading
            // behind a transient network/auth hiccup).
            return new DownloadStatus(DownloadStatus::STATE_UNKNOWN, 0.0, 'Download client query failed: ' . $e->getMessage());
        }

        if (!is_array($rows) || $rows === [] || !is_array($rows[0] ?? null)) {
            // A successful, authenticated query that confirms the hash isn't present —
            // this alone is trustworthy evidence of removal.
            return new DownloadStatus(DownloadStatus::STATE_MISSING, 0.0, 'Torrent not found in the download client.');
        }

        return self::mapTorrentRow($rows[0]);
    }

    public function cancel(string $downloadId, bool $deleteFiles = false): bool
    {
        $row = $this->integrations->qbittorrentIntegration();
        if ($row === null) {
            return false;
        }

        try {
            $sid = $this->login($row);
            $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/delete', [
                'headers' => $this->authHeaders($row, $sid),
                'body'    => ['hashes' => strtolower($downloadId), 'deleteFiles' => $deleteFiles ? 'true' : 'false'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return $response->getStatusCode() === 200;
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('Download client cancel failed', ['hash' => $downloadId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Apply one or more (comma-separated) tags to a torrent. Idempotent: qBittorrent
     * answers 200 for already-tagged and unknown hashes alike, and a missing endpoint
     * or unknown-hash style 404/409 is tolerated too. Transport/auth failures throw —
     * callers doing best-effort re-tagging catch and degrade.
     *
     * The tag is created first (older clients don't auto-create on addTags).
     */
    public function addTags(string $hash, string $tags): void
    {
        $row = $this->requireRow();
        $sid = $this->login($row);

        $this->createTag($row, $sid, $tags);

        $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/addTags', [
            'headers' => $this->authHeaders($row, $sid),
            'body'    => ['hashes' => strtolower($hash), 'tags' => $tags],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        $status = $response->getStatusCode();
        if (($status < 200 || $status >= 300) && $status !== 404 && $status !== 409) {
            throw new \RuntimeException('The download client rejected the tag update (HTTP ' . $status . ').');
        }
    }

    /**
     * Delete a torrent from the client, optionally with its downloaded files.
     * Idempotent: an already-gone hash answers 200 (and 404/409 is tolerated).
     * Transport/auth failures throw — callers catch and degrade.
     */
    public function deleteTorrent(string $hash, bool $deleteFiles): void
    {
        $row = $this->requireRow();
        $sid = $this->login($row);

        $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/delete', [
            'headers' => $this->authHeaders($row, $sid),
            'body'    => ['hashes' => strtolower($hash), 'deleteFiles' => $deleteFiles ? 'true' : 'false'],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        $status = $response->getStatusCode();
        if (($status < 200 || $status >= 300) && $status !== 404 && $status !== 409) {
            throw new \RuntimeException('The download client rejected the torrent delete (HTTP ' . $status . ').');
        }
    }

    /**
     * All torrents in the configured category. Best-effort per the interface
     * contract: an unconfigured client or a failed login/query returns [].
     *
     * @return list<array{id: string, name: string, state: string, progress: float, sizeBytes: int|null, completed: bool}>
     */
    public function listDownloads(): array
    {
        $row = $this->integrations->qbittorrentIntegration();
        if ($row === null || $row->getBaseUrl() === null || $row->getBaseUrl() === '') {
            return [];
        }

        try {
            $sid = $this->login($row);
        } catch (HttpExceptionInterface | \RuntimeException $e) {
            $this->logger->warning('Download client listing failed at login', ['error' => $e->getMessage()]);

            return [];
        }

        $category = $this->integrations->getTorrentClientConfig()->category;

        $out = [];
        foreach ($this->torrentsInCategory($row, $sid, $category) as $t) {
            if (!is_string($t['hash'] ?? null)) {
                continue;
            }
            $hash = strtolower($t['hash']);
            $state = (string) ($t['state'] ?? 'unknown');
            $progress = (float) ($t['progress'] ?? 0.0);

            $out[] = [
                'id'        => $hash,
                'name'      => is_string($t['name'] ?? null) && $t['name'] !== '' ? $t['name'] : $hash,
                'state'     => $state,
                'progress'  => max(0.0, min(100.0, $progress * 100)),
                'sizeBytes' => isset($t['size']) && is_numeric($t['size']) ? (int) $t['size'] : null,
                'completed' => $progress >= 1.0 || in_array($state, self::SEEDING_STATES, true),
            ];
        }

        return $out;
    }

    /**
     * Map one qBittorrent torrents/info row to a DownloadStatus. A finished torrent
     * (progress complete or in a seeding state) reports STATE_SEEDING and carries
     * its content_path (and save_path, needed to resolve content_path under the
     * bind-mounted /downloads root — see TorrentClientConfig::localContentPath) so
     * the poller can move the files out — we keep it seeding rather than removing
     * it, so the torrent stays healthy.
     *
     * @param array<string, mixed> $t
     */
    public static function mapTorrentRow(array $t): DownloadStatus
    {
        $state = (string) ($t['state'] ?? 'unknown');
        $progress = (float) ($t['progress'] ?? 0.0);
        $contentPath = is_string($t['content_path'] ?? null) ? $t['content_path'] : null;
        $savePath = is_string($t['save_path'] ?? null) ? $t['save_path'] : null;
        $speed = isset($t['dlspeed']) && is_numeric($t['dlspeed']) ? (int) $t['dlspeed'] : null;
        $eta = isset($t['eta']) && is_numeric($t['eta']) ? (int) $t['eta'] : null;

        if (in_array($state, self::ERROR_STATES, true)) {
            return DownloadStatus::error('Download client reported state "' . $state . '".');
        }
        if ($progress >= 1.0 || in_array($state, self::SEEDING_STATES, true)) {
            return new DownloadStatus(DownloadStatus::STATE_SEEDING, 100.0, 'Download complete; seeding.', $contentPath, null, null, $savePath);
        }
        if ($state === 'pausedDL') {
            return new DownloadStatus(DownloadStatus::STATE_PAUSED, $progress * 100, 'Paused.', null, $speed, $eta);
        }
        if (in_array($state, self::DOWNLOADING_STATES, true)) {
            return new DownloadStatus(DownloadStatus::STATE_DOWNLOADING, $progress * 100, 'Downloading (' . $state . ').', null, $speed, $eta);
        }

        return new DownloadStatus(DownloadStatus::STATE_QUEUED, $progress * 100, 'State: ' . $state . '.');
    }

    /**
     * Fetch torrents/info rows for a category, optionally hinting the server-side tag
     * filter. The hint is only an optimisation — callers still verify the tag per row,
     * so this works even on qBittorrent versions that ignore the `tag` query param.
     *
     * @return list<array<string, mixed>>
     */
    private function torrentsInCategory(Integration $row, ?string $sid, string $category, ?string $tag = null): array
    {
        $query = ['category' => $category];
        if ($tag !== null) {
            $query['tag'] = $tag;
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . '/api/v2/torrents/info', [
                'headers' => $this->authHeaders($row, $sid),
                'query'   => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $rows = $response->toArray(false);
        } catch (HttpExceptionInterface | \JsonException) {
            return [];
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $t) {
            if (is_array($t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** Hash of the (first) torrent in $category carrying $tag, or null if not present yet. */
    private function findHashByTag(Integration $row, ?string $sid, string $category, string $tag): ?string
    {
        foreach ($this->torrentsInCategory($row, $sid, $category, $tag) as $t) {
            if (is_string($t['hash'] ?? null) && self::rowHasTag($t, $tag)) {
                return strtolower($t['hash']);
            }
        }

        return null;
    }

    /**
     * Remove any torrents carrying $tag (with their files), then drop the tag itself.
     * Best-effort cleanup after a failed hash resolution so a retry isn't blocked by an
     * untracked, still-downloading torrent.
     */
    private function cleanupByTag(Integration $row, ?string $sid, string $category, string $tag): void
    {
        $hashes = [];
        foreach ($this->torrentsInCategory($row, $sid, $category, $tag) as $t) {
            if (is_string($t['hash'] ?? null) && self::rowHasTag($t, $tag)) {
                $hashes[] = strtolower($t['hash']);
            }
        }

        if ($hashes !== []) {
            try {
                $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/delete', [
                    'headers' => $this->authHeaders($row, $sid),
                    'body'    => ['hashes' => implode('|', $hashes), 'deleteFiles' => 'true'],
                    'timeout' => self::TIMEOUT_SECONDS,
                ])->getStatusCode();
            } catch (HttpExceptionInterface $e) {
                $this->logger->warning('Cleanup of unresolved torrent add failed', ['tag' => $tag, 'error' => $e->getMessage()]);
            }
        }

        $this->deleteTag($row, $sid, $tag);
    }

    private static function rowHasTag(array $t, string $tag): bool
    {
        $tags = is_string($t['tags'] ?? null) ? $t['tags'] : '';
        foreach (explode(',', $tags) as $candidate) {
            if (trim($candidate) === $tag) {
                return true;
            }
        }

        return false;
    }

    /** Best-effort: ensure the tag exists before the add (older clients don't auto-create). */
    private function createTag(Integration $row, ?string $sid, string $tag): void
    {
        $this->tagRequest($row, $sid, '/api/v2/torrents/createTags', $tag);
    }

    /** Best-effort: drop the throwaway tag once we're done with it. */
    private function deleteTag(Integration $row, ?string $sid, string $tag): void
    {
        $this->tagRequest($row, $sid, '/api/v2/torrents/deleteTags', $tag);
    }

    private function tagRequest(Integration $row, ?string $sid, string $path, string $tag): void
    {
        try {
            $this->httpClient->request('POST', $this->baseUrl($row) . $path, [
                'headers' => $this->authHeaders($row, $sid),
                'body'    => ['tags' => $tag],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->debug('Tag request failed (continuing)', ['path' => $path, 'tag' => $tag, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Log in and return the SID cookie, cached for this instance. Returns null when
     * the instance has no credentials (qBittorrent with auth disabled / whitelisted
     * host), in which case requests proceed without a cookie.
     */
    private function login(Integration $row): ?string
    {
        if ($this->sidCookie !== null) {
            return $this->sidCookie;
        }
        // Native API-key auth is stateless and qBittorrent rejects the key on
        // /auth/login — skip the cookie flow entirely; authHeaders() carries the key.
        if ($row->getAuthType() === Integration::AUTH_API_KEY) {
            return null;
        }
        $creds = $row->getCredentials();
        $username = (string) ($creds['username'] ?? '');
        $password = (string) ($creds['password'] ?? '');
        if ($username === '') {
            return null;
        }

        $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/auth/login', [
            'headers' => ['Referer' => $this->baseUrl($row)],
            'body'    => ['username' => $username, 'password' => $password],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        // qBittorrent answers a successful login with 200 (body "Ok.") or 204 No
        // Content depending on version/proxy — accept any 2xx. Bad credentials come
        // back as 200 with body "Fails." (a 204 has an empty body, so the check is
        // harmless there).
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300 || stripos($response->getContent(false), 'fail') !== false) {
            throw new \RuntimeException('Download client login failed — check the username and password.');
        }

        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            if (preg_match('/SID=([^;]+)/', $cookie, $m) === 1) {
                return $this->sidCookie = 'SID=' . $m[1];
            }
        }

        // Login OK but no cookie (some reverse-proxy setups) — proceed without one.
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(Integration $row, ?string $sid): array
    {
        $headers = ['Referer' => $this->baseUrl($row)];
        if ($row->getAuthType() === Integration::AUTH_API_KEY) {
            $key = (string) ($row->getCredentials()['api_key'] ?? '');
            if ($key !== '') {
                $headers['Authorization'] = 'Bearer ' . $key;
            }

            return $headers;
        }
        if ($sid !== null) {
            $headers['Cookie'] = $sid;
        }

        return $headers;
    }

    private function requireRow(): Integration
    {
        $row = $this->integrations->qbittorrentIntegration();
        if ($row === null || $row->getBaseUrl() === null || $row->getBaseUrl() === '') {
            throw new \RuntimeException('Download client is not configured.');
        }

        return $row;
    }

    private function baseUrl(Integration $row): string
    {
        return rtrim((string) $row->getBaseUrl(), '/');
    }

    /**
     * A safe multipart filename for an uploaded .torrent, derived from the job's
     * display name. Purely cosmetic — qBittorrent reads the metadata from the file
     * body — but kept readable so the add is recognisable in the client's log.
     */
    private static function torrentFilename(string $name): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._ \-]+/', '_', $name));

        return ($safe !== '' ? mb_substr($safe, 0, 120) : 'download') . '.torrent';
    }

    /** Extract the lowercase v1 info-hash (40 hex) from a magnet link, or null. */
    private function extractInfoHash(string $url): ?string
    {
        if (preg_match('/xt=urn:btih:([0-9a-fA-F]{40})/', $url, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /** Whether the client knows a torrent under $hash (any category). */
    private function torrentExists(Integration $row, ?string $sid, string $hash): bool
    {
        try {
            $rows = $this->httpClient->request('GET', $this->baseUrl($row) . '/api/v2/torrents/info', [
                'headers' => $this->authHeaders($row, $sid),
                'query'   => ['hashes' => $hash],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray(false);
        } catch (HttpExceptionInterface | \JsonException) {
            return false;
        }

        return is_array($rows) && is_array($rows[0] ?? null);
    }

    /**
     * The lowercase v1 info-hash of a .torrent file: the SHA-1 of the raw bencoded
     * `info` dict, located by walking the file's top-level dict without decoding
     * values. Null when the bytes aren't a well-formed bencoded dict carrying an
     * `info` key.
     */
    private static function infoHashFromTorrentFile(string $bytes): ?string
    {
        if (($bytes[0] ?? '') !== 'd') {
            return null;
        }

        try {
            $i = 1;
            while (($bytes[$i] ?? '') !== 'e') {
                $key = self::bencodeString($bytes, $i);
                $start = $i;
                self::bencodeSkipValue($bytes, $i);
                if ($key === 'info') {
                    return sha1(substr($bytes, $start, $i - $start));
                }
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return null;
    }

    /** Parse the bencoded string ("3:foo") at $i, advancing $i past it. */
    private static function bencodeString(string $data, int &$i): string
    {
        $colon = strpos($data, ':', $i);
        if ($colon === false || $colon === $i || !ctype_digit(substr($data, $i, $colon - $i))) {
            throw new \InvalidArgumentException('malformed bencode string');
        }
        $length = (int) substr($data, $i, $colon - $i);
        if ($colon + 1 + $length > \strlen($data)) {
            throw new \InvalidArgumentException('truncated bencode string');
        }
        $i = $colon + 1 + $length;

        return substr($data, $colon + 1, $length);
    }

    /** Advance $i past the bencoded value (int, string, list or dict) starting there. */
    private static function bencodeSkipValue(string $data, int &$i): void
    {
        $c = $data[$i] ?? '';
        if ($c === 'i') {
            $end = strpos($data, 'e', $i);
            if ($end === false) {
                throw new \InvalidArgumentException('truncated bencode integer');
            }
            $i = $end + 1;

            return;
        }
        if ($c === 'l' || $c === 'd') {
            ++$i;
            while (($data[$i] ?? '') !== 'e') {
                if ($i >= \strlen($data)) {
                    throw new \InvalidArgumentException('truncated bencode container');
                }
                if ($c === 'd') {
                    self::bencodeString($data, $i);
                }
                self::bencodeSkipValue($data, $i);
            }
            ++$i;

            return;
        }

        self::bencodeString($data, $i);
    }
}
