<?php

declare(strict_types=1);

namespace App\Integration\Prowlarr;

use App\Entity\Integration;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use App\Support\AudioFormat;
use App\Support\EbookFormat;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Searches Prowlarr's aggregated indexers for audiobook torrents and maps the
 * results to ReleaseCandidate. Connection details (base URL + API key) come from
 * the `prowlarr` Integration row; the search scope (categories) from its
 * ProwlarrConfig. Network errors never throw out of search()/testConnection() —
 * they degrade to an empty result / a failed status so the caller can fail over.
 */
final class ProwlarrClient
{
    /** The indexer manager's native JSON search path; auth via the X-Api-Key header. */
    private const SEARCH_PATH = '/api/v1/search';
    private const STATUS_PATH = '/api/v1/system/status';

    private const TIMEOUT_SECONDS = 30;
    private const MAX_RESULTS = 100;

    /** Torznab "Books" / "Books/EBook" categories used when searching for book torrents. */
    private const EBOOK_CATEGORIES = [7000, 7020];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly IntegrationRepository $integrations,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        $row = $this->integrations->findByKind(Integration::KIND_PROWLARR);

        return $row !== null
            && $row->isEnabled()
            && $row->getBaseUrl() !== null && $row->getBaseUrl() !== ''
            && ($row->getCredentials()['token'] ?? '') !== '';
    }

    /**
     * Search Prowlarr for the plan's book and return audiobook torrent candidates.
     * Returns [] when Prowlarr is unconfigured or the request fails.
     *
     * $searchMethod (one of ProwlarrConfig::METHODS) overrides the operator's
     * saved default — the interactive panel's per-search toggle. With
     * METHOD_CATEGORIES the query carries the Torznab category scope; METHOD_RAW
     * omits it entirely (some indexers ignore or mis-map category filters);
     * METHOD_FILTERED also omits it, then drops results the app can positively
     * classify as the wrong content type.
     *
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan, ?string $searchMethod = null): array
    {
        $row = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        if ($row === null || !$row->isEnabled()) {
            return [];
        }
        $config = $this->integrations->getProwlarrConfig();
        $method = $searchMethod !== null && in_array($searchMethod, ProwlarrConfig::METHODS, true)
            ? $searchMethod
            : $config->searchMethod;
        $isAudiobook = $plan->contentType === ReleaseCandidate::CONTENT_AUDIOBOOK;

        $query = [
            'query' => $plan->primaryQuery(),
            'type'  => 'search',
            'limit' => self::MAX_RESULTS,
        ];
        if ($method === ProwlarrConfig::METHOD_CATEGORIES) {
            $query['categories'] = self::withParentCategories($isAudiobook ? $config->categories : self::EBOOK_CATEGORIES);
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . self::SEARCH_PATH, [
                'headers' => ['X-Api-Key' => (string) ($row->getCredentials()['token'] ?? '')],
                'query'   => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $rows = $response->toArray();
        } catch (HttpExceptionInterface | \JsonException $e) {
            $this->logger->warning('Indexer search failed', ['error' => $e->getMessage(), 'query' => $plan->primaryQuery()]);

            return [];
        }

        $candidates = self::mapResults($rows, $plan->contentType);
        if ($method === ProwlarrConfig::METHOD_FILTERED) {
            $candidates = self::filterByContentType($candidates, $plan->contentType);
        }

        return $candidates;
    }

    /**
     * The post-search half of METHOD_FILTERED: keep a candidate unless it is
     * positively classified as the *other* content type. Classification prefers
     * the category-derived type (extra['type']); rows without a usable category
     * fall back to a format token in the title, scanned across both extension
     * sets. Unclassifiable rows are kept — the scorer downstream ranks them, and
     * dropping them would silently hide releases from indexers with bare titles.
     *
     * @param list<ReleaseCandidate> $candidates
     *
     * @return list<ReleaseCandidate>
     */
    public static function filterByContentType(array $candidates, string $contentType): array
    {
        if (!in_array($contentType, [ReleaseCandidate::CONTENT_AUDIOBOOK, ReleaseCandidate::CONTENT_EBOOK], true)) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static function (ReleaseCandidate $c) use ($contentType): bool {
                $type = $c->extra['type'] ?? null;
                if ($type === null) {
                    $type = self::contentTypeFromFormatToken($c->title);
                }

                return $type === null || $type === $contentType;
            },
        ));
    }

    /**
     * Classify a release title by the format token it carries ("[M4B]", ".epub"),
     * checking the audio extensions first, then the ebook ones. Null when the
     * title names no known format.
     */
    private static function contentTypeFromFormatToken(string $title): ?string
    {
        if (self::matchExtension($title, AudioFormat::EXTENSIONS) !== null) {
            return ReleaseCandidate::CONTENT_AUDIOBOOK;
        }
        if (self::matchExtension($title, EbookFormat::EXTENSIONS) !== null) {
            return ReleaseCandidate::CONTENT_EBOOK;
        }

        return null;
    }

    /**
     * Widen a Torznab category filter with each id's parent (3030 → 3000, 7020 →
     * 7000). Many indexers file releases only under the broad parent category, so
     * a subcategory-only filter silently excludes them — searching for the
     * default [3030] scope alone can return nothing while the same query
     * unfiltered shows plenty of audiobooks. The scorer downstream separates the
     * right book from the broader category's noise.
     *
     * @param list<int> $categories
     *
     * @return list<int>
     */
    public static function withParentCategories(array $categories): array
    {
        $out = $categories;
        foreach ($categories as $id) {
            $parent = intdiv($id, 1000) * 1000;
            if ($parent > 0 && !in_array($parent, $out, true)) {
                $out[] = $parent;
            }
        }

        return $out;
    }

    /**
     * Map raw indexer search rows to torrent ReleaseCandidates of the given content
     * type. Pure — no I/O, static — so the mapping is unit-testable. Non-torrent and
     * link-less rows are skipped (we can only hand a magnet/URL to a torrent client).
     *
     * @param array<int, mixed> $rows
     *
     * @return list<ReleaseCandidate>
     */
    public static function mapResults(array $rows, string $contentType = ReleaseCandidate::CONTENT_AUDIOBOOK): array
    {
        $audio = $contentType === ReleaseCandidate::CONTENT_AUDIOBOOK;
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $protocol = strtolower((string) ($row['protocol'] ?? 'torrent'));
            if ($protocol !== 'torrent') {
                continue;
            }
            $link = self::firstString($row, ['magnetUrl', 'downloadUrl', 'guid']);
            $title = trim((string) ($row['title'] ?? ''));
            if ($link === null || $title === '') {
                continue;
            }

            $categoryIds = self::deriveCategoryIds($row['categories'] ?? null);

            $out[] = new ReleaseCandidate(
                source: Integration::KIND_PROWLARR,
                sourceId: (string) ($row['guid'] ?? $link),
                title: $title,
                format: self::deriveFormat($title, $audio),
                language: null,
                sizeBytes: isset($row['size']) && is_numeric($row['size']) ? (int) $row['size'] : null,
                downloadUrl: $link,
                infoUrl: self::firstString($row, ['infoUrl', 'guid']),
                protocol: ReleaseCandidate::PROTOCOL_TORRENT,
                indexer: self::firstString($row, ['indexer']),
                seeders: isset($row['seeders']) && is_numeric($row['seeders']) ? (int) $row['seeders'] : null,
                downloads: isset($row['grabs']) && is_numeric($row['grabs']) ? (int) $row['grabs'] : null,
                contentType: $contentType,
                author: null,
                isbns: [],
                publisher: null,
                year: self::deriveYear($title),
                extra: [
                    'leechers'    => isset($row['leechers']) && is_numeric($row['leechers']) ? (int) $row['leechers'] : null,
                    'flags'       => self::deriveFlags($row['indexerFlags'] ?? null),
                    'categoryIds' => $categoryIds,
                    'categories'  => self::deriveCategoryNames($row['categories'] ?? null),
                    'type'        => self::deriveContentType($categoryIds, $contentType),
                    'publishDate' => self::derivePublishDate($row['publishDate'] ?? null),
                ],
            );
        }

        return $out;
    }

    /**
     * Quick connectivity check against Prowlarr's status endpoint.
     *
     * @return array{0: bool, 1: string}
     */
    public function testConnection(): array
    {
        $row = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        if ($row === null || $row->getBaseUrl() === null || $row->getBaseUrl() === '') {
            return [false, 'Indexer manager URL is not set.'];
        }
        if (($row->getCredentials()['token'] ?? '') === '') {
            return [false, 'Indexer manager API key is not set.'];
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . self::STATUS_PATH, [
                'headers' => ['X-Api-Key' => (string) $row->getCredentials()['token']],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                return [false, 'Indexer manager returned HTTP ' . $response->getStatusCode() . '.'];
            }
            $version = is_array($data) ? (string) ($data['version'] ?? '') : '';

            return [true, $version !== '' ? 'Connected to indexers ' . $version . '.' : 'Connected to indexers.'];
        } catch (HttpExceptionInterface | \JsonException $e) {
            return [false, 'Connection failed: ' . $e->getMessage()];
        }
    }

    private function baseUrl(Integration $row): string
    {
        return rtrim((string) $row->getBaseUrl(), '/');
    }

    /**
     * Derive a format token from a release title (e.g. a "[M4B]" tag or an ".epub"
     * mention). The extension set matching the searched content type is scanned
     * first so a title naming both resolves to the wanted kind; the other set is a
     * fallback so raw/unfiltered searches still label off-type rows instead of
     * showing "?". Returns the lowercased format, or null when none is found.
     */
    private static function deriveFormat(string $title, bool $audio): ?string
    {
        $preferred = $audio ? AudioFormat::EXTENSIONS : EbookFormat::EXTENSIONS;
        $fallback  = $audio ? EbookFormat::EXTENSIONS : AudioFormat::EXTENSIONS;

        return self::matchExtension($title, $preferred) ?? self::matchExtension($title, $fallback);
    }

    /**
     * First extension from $extensions appearing as a word in the title, or null.
     *
     * @param list<string> $extensions
     */
    private static function matchExtension(string $title, array $extensions): ?string
    {
        $lower = strtolower($title);
        foreach ($extensions as $ext) {
            if (preg_match('/\b' . preg_quote($ext, '/') . '\b/', $lower) === 1) {
                return $ext;
            }
        }

        return null;
    }

    private static function deriveYear(string $title): ?string
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $title, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * Normalize an indexer's flag list. Prowlarr sends `indexerFlags` either as a
     * list of strings (["freeleech"]) or a list of objects ([{"name":"freeleech"}]);
     * both are accepted. Flags are lowercased and trimmed, empties dropped and
     * duplicates removed, with the original order preserved.
     *
     * @return list<string>
     */
    private static function deriveFlags(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $flags = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $entry = $entry['name'] ?? null;
            }
            if (!is_string($entry)) {
                continue;
            }
            $flag = strtolower(trim($entry));
            if ($flag === '' || in_array($flag, $flags, true)) {
                continue;
            }
            $flags[] = $flag;
        }

        return $flags;
    }

    /**
     * Flatten a row's `categories` to a deduped list of Torznab ids, order preserved.
     * Prowlarr sends either bare ids ([3030]) or objects ([{"id":3030,"name":"..."}]),
     * the latter sometimes carrying a nested `subCategories` list; all shapes are
     * accepted and anything unrecognizable is skipped.
     *
     * @return list<int>
     */
    private static function deriveCategoryIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach (self::flattenCategories($raw) as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if (!is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The human labels behind the same `categories` list — trimmed, deduped, order
     * preserved. Bare-id rows carry no names, so they yield [].
     *
     * @return list<string>
     */
    private static function deriveCategoryNames(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        foreach (self::flattenCategories($raw) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
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
     * Depth-first walk of a categories list, yielding each entry (id or object) and
     * then its `subCategories`, so nested trees flatten in document order.
     *
     * @param array<int|string, mixed> $raw
     *
     * @return list<mixed>
     */
    private static function flattenCategories(array $raw): array
    {
        $flat = [];
        foreach ($raw as $entry) {
            $flat[] = $entry;
            if (is_array($entry) && is_array($entry['subCategories'] ?? null)) {
                foreach (self::flattenCategories($entry['subCategories']) as $child) {
                    $flat[] = $child;
                }
            }
        }

        return $flat;
    }

    /**
     * Classify a release from its Torznab categories: the 3000s are Audio, the 7000s
     * are Books. When a row claims both (some indexers cross-post), the plan's own
     * content type breaks the tie if it names one of the two; otherwise whichever
     * range appears first in the list wins. No book/audio category at all → null.
     *
     * @param list<int> $categoryIds
     */
    private static function deriveContentType(array $categoryIds, string $contentType): ?string
    {
        $hasAudio = false;
        $hasEbook = false;
        $first = null;
        foreach ($categoryIds as $id) {
            $kind = match (true) {
                $id >= 3000 && $id <= 3999 => ReleaseCandidate::CONTENT_AUDIOBOOK,
                $id >= 7000 && $id <= 7999 => ReleaseCandidate::CONTENT_EBOOK,
                default                    => null,
            };
            if ($kind === null) {
                continue;
            }
            $first ??= $kind;
            $hasAudio = $hasAudio || $kind === ReleaseCandidate::CONTENT_AUDIOBOOK;
            $hasEbook = $hasEbook || $kind === ReleaseCandidate::CONTENT_EBOOK;
        }

        if ($hasAudio && $hasEbook) {
            return in_array($contentType, [ReleaseCandidate::CONTENT_AUDIOBOOK, ReleaseCandidate::CONTENT_EBOOK], true)
                ? $contentType
                : $first;
        }

        return $first;
    }

    /**
     * Normalize a row's publish timestamp to a plain `YYYY-MM-DD` day. Anything that
     * is not a parseable date string (or a numeric epoch) degrades to null rather
     * than throwing — indexers are inconsistent about this field.
     */
    private static function derivePublishDate(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = '@' . (int) $raw;
        }
        // Require a digit: relative words ("now", "tomorrow") parse happily but are
        // never a real publish timestamp, so they count as garbage here.
        if (!is_string($raw) || preg_match('/\d/', $raw) !== 1) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($raw)))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private static function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }
}
