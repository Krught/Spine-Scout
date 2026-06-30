<?php

declare(strict_types=1);

namespace App\Download\Client;

use App\Entity\Integration;
use App\Search\Source\ReleaseCandidate;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * qBittorrent WebUI (v2 API) download client for the torrent protocol. Unlike the
 * synchronous HttpDownloadClient, a torrent download is asynchronous: addDownload()
 * submits the magnet/URL and returns immediately with the torrent hash, and the
 * torrent poller later calls getStatus() until the torrent finishes seeding.
 *
 * Connection (base URL + username/password) comes from the `qbittorrent`
 * Integration row. The login cookie is fetched lazily and cached for this
 * instance's lifetime (one worker invocation).
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
            return [false, 'Connection failed: ' . $e->getMessage()];
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
     * @param array<string, mixed> $options
     */
    public function addDownload(string $url, string $name, array $options = []): string
    {
        $row = $this->requireRow();
        $config = $this->integrations->getTorrentClientConfig();
        $sid = $this->login($row);

        // Magnets carry the hash in the link — no polling needed.
        $hash = $this->extractInfoHash($url);
        $tag = $hash === null ? self::ADD_TAG_PREFIX . bin2hex(random_bytes(8)) : null;

        $payload = ['urls' => $url, 'category' => $config->category];
        if ($tag !== null) {
            $this->createTag($row, $sid, $tag);
            $payload['tags'] = $tag;
        }

        $response = $this->httpClient->request('POST', $this->baseUrl($row) . '/api/v2/torrents/add', [
            'headers' => $this->authHeaders($row, $sid),
            'body'    => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        $body = trim($response->getContent(false));
        if ($response->getStatusCode() !== 200 || stripos($body, 'fail') !== false) {
            throw new \RuntimeException('The download client rejected the torrent add (' . $response->getStatusCode() . ' ' . $body . ').');
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
        } catch (HttpExceptionInterface | \JsonException $e) {
            return new DownloadStatus(DownloadStatus::STATE_UNKNOWN, 0.0, 'Download client query failed: ' . $e->getMessage());
        }

        if (!is_array($rows) || $rows === [] || !is_array($rows[0] ?? null)) {
            return new DownloadStatus(DownloadStatus::STATE_UNKNOWN, 0.0, 'Torrent not found in the download client yet.');
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
     * Map one qBittorrent torrents/info row to a DownloadStatus. A finished torrent
     * (progress complete or in a seeding state) reports STATE_SEEDING and carries
     * its content_path so the poller can move the files out — we keep it seeding
     * rather than removing it, so the torrent stays healthy.
     *
     * @param array<string, mixed> $t
     */
    public static function mapTorrentRow(array $t): DownloadStatus
    {
        $state = (string) ($t['state'] ?? 'unknown');
        $progress = (float) ($t['progress'] ?? 0.0);
        $contentPath = is_string($t['content_path'] ?? null) ? $t['content_path'] : null;
        $speed = isset($t['dlspeed']) && is_numeric($t['dlspeed']) ? (int) $t['dlspeed'] : null;
        $eta = isset($t['eta']) && is_numeric($t['eta']) ? (int) $t['eta'] : null;

        if (in_array($state, self::ERROR_STATES, true)) {
            return DownloadStatus::error('Download client reported state "' . $state . '".');
        }
        if ($progress >= 1.0 || in_array($state, self::SEEDING_STATES, true)) {
            return new DownloadStatus(DownloadStatus::STATE_SEEDING, 100.0, 'Download complete; seeding.', $contentPath);
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
        if ($response->getStatusCode() !== 200 || stripos($response->getContent(false), 'fail') !== false) {
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

    /** Extract the lowercase v1 info-hash (40 hex) from a magnet link, or null. */
    private function extractInfoHash(string $url): ?string
    {
        if (preg_match('/xt=urn:btih:([0-9a-fA-F]{40})/', $url, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }
}
