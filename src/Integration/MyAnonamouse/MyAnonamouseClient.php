<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads MyAnonamouse's JSON endpoints: the current freeleech catalog, the account
 * summary behind the connection test, and the dynamic-seedbox IP keepalive. Every
 * call carries the operator's `mam_id` session cookie and persists the rotated value
 * MAM hands back, or the session dies. This is a private tracker: requests are
 * sequential, paced, hard-capped, and an auth failure degrades to []/null/ok=false
 * without a single retry — never throwing into the scheduler.
 */
final class MyAnonamouseClient
{
    private const SEARCH_PATH = '/tor/js/loadSearchJSONbasic.php';
    private const USER_INFO_PATH = '/jsonLoad.php?snatch_summary';
    /** The IP keepalive lives on the tracker subdomain, not the configured base URL. */
    private const DYNAMIC_SEEDBOX_URL = 'https://t.myanonamouse.net/json/dynamicSeedbox.php';

    private const USER_AGENT = 'SpineScout/1.0 (+https://spinescout.local)';
    private const TIMEOUT_SECONDS = 20;
    private const PER_PAGE = 100;
    private const MAX_PAGES = 10;
    private const PAGE_DELAY_MICROSECONDS = 1000000;

    /**
     * MAM's search endpoint serves at most ~200 rows per query no matter the paging
     * params, so one query is only ever a window onto the catalog. These cap how many
     * date windows a single sweep may walk backwards: the regular freeleech pool is
     * small enough that 25 windows walk it to completion in practice, while the VIP
     * set is effectively the whole tracker — 10 windows deliberately take only the
     * newest ~2000 torrents and leave the rest.
     */
    private const REGULAR_MAX_WINDOWS = 25;
    private const VIP_MAX_WINDOWS = 10;

    private const VIP_CLASSES = ['vip', 'elite vip'];

    /** MAM's own spellings: 'fl' is freeleech only, 'fl-VIP' is freeleech OR VIP, 'VIP' is VIP only. */
    public const SEARCH_TYPE_FREELEECH = 'fl';
    public const SEARCH_TYPE_FREELEECH_OR_VIP = 'fl-VIP';
    public const SEARCH_TYPE_VIP = 'VIP';
    private const SEARCH_TYPES = [self::SEARCH_TYPE_FREELEECH, self::SEARCH_TYPE_FREELEECH_OR_VIP, self::SEARCH_TYPE_VIP];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MyAnonamouseSettingsProvider $settings,
        private readonly LoggerInterface $logger,
        /** Pacing between every MAM request; only ever lowered by tests. */
        private readonly int $pageDelayMicroseconds = self::PAGE_DELAY_MICROSECONDS,
    ) {
    }

    public function isConfigured(): bool
    {
        $cookie = $this->settings->getMamSessionCookie();

        return $this->settings->getMyAnonamouseConfig()->enabled
            && $cookie !== null && trim($cookie) !== '';
    }

    /**
     * @return array{ok: bool, message: string, username?: string, class?: string, ratio?: string|float|null, isVip?: bool}
     */
    public function testConnection(): array
    {
        $config = $this->settings->getMyAnonamouseConfig();
        if (trim($config->baseUrl) === '') {
            return ['ok' => false, 'message' => 'MyAnonamouse URL is not set.'];
        }
        $cookie = $this->settings->getMamSessionCookie();
        if ($cookie === null || trim($cookie) === '') {
            return ['ok' => false, 'message' => 'MyAnonamouse session cookie is not set.'];
        }

        $info = $this->fetchUserInfo();
        if ($info === null) {
            return ['ok' => false, 'message' => 'Connection failed: MyAnonamouse did not return account JSON — the session cookie is likely expired or IP-locked elsewhere.'];
        }

        $message = 'Connected as ' . ($info['username'] !== '' ? $info['username'] : 'unknown user');
        if ($info['class'] !== '') {
            $message .= ' (' . $info['class'] . ')';
        }
        if ($info['ratio'] !== null) {
            $message .= ', ratio ' . $info['ratio'];
        }

        return [
            'ok'       => true,
            'message'  => $message . '.',
            'username' => $info['username'],
            'class'    => $info['class'],
            'ratio'    => $info['ratio'],
            'isVip'    => $info['isVip'],
        ];
    }

    /**
     * @return array{username: string, class: string, ratio: string|float|null, isVip: bool}|null
     */
    public function fetchUserInfo(): ?array
    {
        $data = $this->requestJson('GET', $this->baseUrl() . self::USER_INFO_PATH);
        if ($data === null) {
            return null;
        }

        $class = self::firstString($data, ['class', 'classname', 'class_name', 'className']) ?? '';
        $ratio = $data['ratio'] ?? null;

        return [
            'username' => self::firstString($data, ['username', 'user_name', 'name']) ?? '',
            'class'    => $class,
            'ratio'    => is_string($ratio) || is_float($ratio) || is_int($ratio) ? (is_int($ratio) ? (float) $ratio : $ratio) : null,
            'isVip'    => in_array(strtolower(trim($class)), self::VIP_CLASSES, true),
        ];
    }

    /**
     * Every torrent currently freeleech in one main category (13 = audiobooks,
     * 14 = e-books). MAM's search endpoint refuses to serve more than ~200 rows for
     * any one query however it is paged, so a sweep is a walk backwards in time: each
     * "window" is a newest-first query paged at 100 per request up to MAX_PAGES, and
     * the next window repeats the query with `tor[endDate]` pinned to the oldest
     * `added` date collected so far. The boundary is inclusive — the same day straddles
     * two windows — so rows are deduped by torrent id for the whole call.
     *
     * The walk stops on the first window that exhausts MAM's own result total, that
     * yields no new-to-this-call rows, or that hits the window cap: REGULAR_MAX_WINDOWS
     * for the regular freeleech pool, and the smaller VIP_MAX_WINDOWS for the VIP sets,
     * whose catalog is effectively the whole tracker and is deliberately truncated to
     * the newest slice.
     *
     * $searchType selects MAM's own set: the default 'fl' is the regular freeleech
     * catalog, 'fl-VIP' is the superset that also carries the VIP-only torrents.
     * An unknown value is a programming error and degrades to [] like any failure.
     *
     * $maxItems caps how much of the set the caller wants: the walk stops as soon as it holds
     * that many rows and the list is truncated to exactly that length. Because the walk is
     * newest-first by `added`, the cap yields the newest N — which is the only way the VIP
     * pool (effectively the whole tracker) is ever pulled. null keeps the full walk; the
     * window caps above stay in force either way as the backstop.
     *
     * Failure degrades by how much was already in hand: a failed very first request
     * returns [], which is what the refresher reads as "the sweep failed", while a
     * failure once rows have been collected returns those rows as a partial sweep.
     * Requests are paced identically across pages and across windows.
     *
     * @return list<MamRelease>
     */
    public function fetchFreeleech(int $mainCat, string $searchType = self::SEARCH_TYPE_FREELEECH, ?int $maxItems = null): array
    {
        if (!in_array($searchType, self::SEARCH_TYPES, true)) {
            $this->logger->warning('MyAnonamouse search type is not supported', ['searchType' => $searchType]);

            return [];
        }
        if (!$this->isConfigured()) {
            return [];
        }
        // A non-positive cap asks for nothing, and asking MAM for nothing is a wasted request.
        if ($maxItems !== null && $maxItems <= 0) {
            return [];
        }

        $maxWindows = $searchType === self::SEARCH_TYPE_FREELEECH ? self::REGULAR_MAX_WINDOWS : self::VIP_MAX_WINDOWS;

        /** @var list<MamRelease> $out */
        $out = [];
        /** @var array<int, true> $seen */
        $seen = [];
        $oldest = null;
        $endDate = null;
        $reportedFound = null;
        $requests = 0;
        $window = 0;
        $capped = false;

        while ($window < $maxWindows) {
            $window++;
            $offset = 0;
            $newRows = 0;
            $exhausted = false;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                if ($requests > 0) {
                    usleep($this->pageDelayMicroseconds);
                }
                $requests++;

                $data = $this->requestJson('POST', $this->baseUrl() . self::SEARCH_PATH, [
                    'body' => self::searchPayload($mainCat, $offset, $searchType, $endDate),
                ]);
                if ($data === null) {
                    $out = self::truncate($out, $maxItems);
                    $this->logSweep($searchType, $mainCat, $window, $out, $reportedFound, true);

                    // The refresher tells a failed sweep from an empty one by the empty
                    // list, so only a sweep that never collected anything may report [].
                    return $out;
                }

                $found   = isset($data['found']) && is_numeric($data['found']) ? (int) $data['found'] : 0;
                $perPage = isset($data['perpage']) && is_numeric($data['perpage']) ? (int) $data['perpage'] : self::PER_PAGE;
                $start   = isset($data['start']) && is_numeric($data['start']) ? (int) $data['start'] : $offset;
                if ($perPage < 1) {
                    $perPage = self::PER_PAGE;
                }
                $reportedFound ??= $found;

                $rows = $data['data'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $release = self::mapRelease($row);
                    if ($release->mamTorrentId !== 0) {
                        if (isset($seen[$release->mamTorrentId])) {
                            continue;
                        }
                        $seen[$release->mamTorrentId] = true;
                    }
                    $out[] = $release;
                    $newRows++;
                    if ($release->addedAt !== null && ($oldest === null || $release->addedAt < $oldest)) {
                        $oldest = $release->addedAt;
                    }
                }

                // The caller's newest-N cap is satisfied: stop mid-window, no further request.
                if ($maxItems !== null && \count($out) >= $maxItems) {
                    $capped = true;

                    break;
                }

                if ($start + $perPage >= $found) {
                    $exhausted = true;

                    break;
                }
                $offset = $start + $perPage;
            }

            // MAM served its whole result set, the caller has all it asked for, the window added
            // nothing new, or nothing carries a date to pin the next window to: the walk is over.
            if ($capped || $exhausted || $newRows === 0 || $oldest === null) {
                break;
            }
            $endDate = $oldest->format('Y-m-d');
        }

        $out = self::truncate($out, $maxItems);
        $this->logSweep($searchType, $mainCat, $window, $out, $reportedFound, false);

        return $out;
    }

    /**
     * @param list<MamRelease> $out
     *
     * @return list<MamRelease>
     */
    private static function truncate(array $out, ?int $maxItems): array
    {
        return $maxItems !== null && \count($out) > $maxItems ? \array_slice($out, 0, $maxItems) : $out;
    }

    /**
     * One line per sweep, carrying the number MAM itself reports for the query so the
     * real pool size behind the ~200-row cap shows up in logs and probe output.
     *
     * @param list<MamRelease> $out
     */
    private function logSweep(string $searchType, int $mainCat, int $windows, array $out, ?int $found, bool $partial): void
    {
        $this->logger->info('MyAnonamouse freeleech sweep finished', [
            'searchType' => $searchType,
            'mainCat'    => $mainCat,
            'windows'    => $windows,
            'collected'  => count($out),
            'found'      => $found,
            'partial'    => $partial,
        ]);
    }

    /**
     * Re-register the server's current public IP with the MAM session. Only works
     * for sessions the operator created with the dynamic-IP flag; false on anything
     * else, including a refusal from MAM.
     */
    public function updateDynamicSeedboxIp(): bool
    {
        $data = $this->requestJson('GET', self::DYNAMIC_SEEDBOX_URL);
        if ($data === null) {
            return false;
        }

        $success = $data['Success'] ?? $data['success'] ?? false;
        if (!self::toBool($success)) {
            $this->logger->info('MyAnonamouse dynamic seedbox update refused', [
                'message' => self::firstString($data, ['msg', 'message']) ?? '',
            ]);

            return false;
        }

        return true;
    }

    /**
     * `dateDesc` is the ordering the date walk depends on — newest first by added date,
     * MAM's own spelling, the same one Prowlarr sends. `$endDate` ('YYYY-MM-DD',
     * inclusive) is what moves the window backwards; the first window omits it.
     *
     * @return array<string, mixed>
     */
    private static function searchPayload(int $mainCat, int $offset, string $searchType, ?string $endDate): array
    {
        $tor = [
            'text'        => '',
            'srchIn'      => ['title' => 'true', 'author' => 'true', 'narrator' => 'true'],
            'searchType'  => $searchType,
            'main_cat'    => [$mainCat],
            'sortType'    => 'dateDesc',
            'perpage'     => (string) self::PER_PAGE,
            'startNumber' => (string) $offset,
        ];
        if ($endDate !== null) {
            $tor['endDate'] = $endDate;
        }

        return [
            'tor'        => $tor,
            'thumbnails' => '1',
        ];
    }

    /**
     * One authenticated JSON call. Returns the decoded body, or null when the call
     * failed in any way MAM expresses failure: a non-200, a 403, or the HTML login
     * page it serves instead of JSON for a dead cookie. Rotated cookies are picked
     * off the response first, so even a failed call keeps the session alive.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function requestJson(string $method, string $url, array $options = []): ?array
    {
        $cookie = $this->settings->getMamSessionCookie();
        if ($cookie === null || trim($cookie) === '') {
            return null;
        }
        $cookie = trim($cookie);

        $config = $this->settings->getMyAnonamouseConfig();
        $options['headers'] = [
            'Cookie'     => 'mam_id=' . $cookie,
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'application/json',
        ];
        $options['timeout'] = self::TIMEOUT_SECONDS;
        if ($config->proxyUrl !== null && trim($config->proxyUrl) !== '') {
            $options['proxy'] = trim($config->proxyUrl);
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $body     = $response->getContent(false);
            $this->syncRotatedCookie($response, $cookie);

            if ($status !== 200) {
                $this->logger->warning('MyAnonamouse request failed', ['url' => $url, 'status' => $status]);

                return null;
            }

            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                $this->logger->warning('MyAnonamouse returned a non-object body', ['url' => $url]);

                return null;
            }

            return $data;
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('MyAnonamouse request failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        } catch (\JsonException) {
            $this->logger->warning('MyAnonamouse returned HTML instead of JSON — the session cookie is likely expired', ['url' => $url]);

            return null;
        }
    }

    /**
     * Persist a rotated `mam_id` off the response's Set-Cookie. The value itself is
     * a live credential and is never logged.
     */
    private function syncRotatedCookie(ResponseInterface $response, string $current): void
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (HttpExceptionInterface) {
            return;
        }

        foreach ($headers['set-cookie'] ?? [] as $line) {
            if (!is_string($line) || preg_match('/(?:^|;\s*)mam_id=([^;]*)/', $line, $m) !== 1) {
                continue;
            }
            $value = trim($m[1]);
            if ($value === '' || $value === $current) {
                continue;
            }
            $this->settings->persistRotatedMamSessionCookie($value);
            $this->logger->info('MyAnonamouse rotated the session cookie; persisted the new value');

            return;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRelease(array $row): MamRelease
    {
        $mainCat = isset($row['main_cat']) && is_numeric($row['main_cat']) ? (int) $row['main_cat'] : 0;

        return new MamRelease(
            mamTorrentId: isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
            title: trim((string) ($row['title'] ?? '')),
            authors: self::decodeNames($row['author_info'] ?? null),
            narrators: self::decodeNames($row['narrator_info'] ?? null),
            audiobook: $mainCat === MyAnonamouseConfig::MAIN_CAT_AUDIOBOOK,
            catName: self::firstString($row, ['catname', 'cat_name', 'cat']),
            langCode: self::firstString($row, ['lang_code', 'langCode', 'language']),
            filetypes: self::firstString($row, ['filetypes', 'filetype']),
            sizeBytes: self::parseSize($row['size'] ?? null),
            seeders: self::toInt($row['seeders'] ?? null),
            leechers: self::toInt($row['leechers'] ?? null),
            timesCompleted: self::toInt($row['times_completed'] ?? null),
            vip: self::toBool($row['vip'] ?? null),
            flVip: self::toBool($row['fl_vip'] ?? null),
            free: self::toBool($row['free'] ?? null),
            personalFreeleech: self::toBool($row['personal_freeleech'] ?? null),
            dlHash: self::firstString($row, ['dl']),
            thumbnailUrl: self::firstString($row, ['thumbnail', 'thumb', 'poster']),
            addedAt: self::parseAdded($row['added'] ?? null),
        );
    }

    /**
     * MAM ships `author_info`/`narrator_info` as a JSON-encoded `{id: "Name"}` map —
     * sometimes an empty string, sometimes null, occasionally already decoded. All
     * shapes yield a deduped list of names; unparseable ones yield [].
     *
     * @return list<string>
     */
    private static function decodeNames(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            try {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '' || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Bytes behind MAM's human size strings ("1.2 GiB"). Binary units are the site's
     * own; the decimal spellings are accepted as the same power-of-two multiplier
     * because that is what MAM means by them. Unparseable → null.
     */
    private static function parseSize(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            return (int) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/^\s*([\d.,]+)\s*([KMGTP]?i?B)?\s*$/i', $raw, $m) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '', $m[1]);
        $multiplier = match (strtoupper($m[2] ?? 'B')) {
            '', 'B'      => 1,
            'KB', 'KIB'  => 1024,
            'MB', 'MIB'  => 1024 ** 2,
            'GB', 'GIB'  => 1024 ** 3,
            'TB', 'TIB'  => 1024 ** 4,
            'PB', 'PIB'  => 1024 ** 5,
            default      => null,
        };

        return $multiplier === null ? null : (int) round($number * $multiplier);
    }

    /** MAM stamps `added` as `yyyy-MM-dd HH:mm:ss` in UTC. */
    private static function parseAdded(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($raw), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function toInt(mixed $raw): int
    {
        return is_numeric($raw) ? (int) $raw : 0;
    }

    private static function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw > 0;
        }
        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private static function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim(trim($this->settings->getMyAnonamouseConfig()->baseUrl), '/');
    }
}
