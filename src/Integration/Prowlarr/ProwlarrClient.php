<?php

declare(strict_types=1);

namespace App\Integration\Prowlarr;

use App\Entity\Integration;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
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
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan): array
    {
        $row = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        if ($row === null || !$row->isEnabled()) {
            return [];
        }
        $config = $this->integrations->getProwlarrConfig();
        $isAudiobook = $plan->contentType === ReleaseCandidate::CONTENT_AUDIOBOOK;
        $categories = $isAudiobook ? $config->categories : self::EBOOK_CATEGORIES;

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl($row) . self::SEARCH_PATH, [
                'headers' => ['X-Api-Key' => (string) ($row->getCredentials()['token'] ?? '')],
                'query'   => [
                    'query'      => $plan->primaryQuery(),
                    'categories' => $categories,
                    'type'       => 'search',
                    'limit'      => self::MAX_RESULTS,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $rows = $response->toArray();
        } catch (HttpExceptionInterface | \JsonException $e) {
            $this->logger->warning('Indexer search failed', ['error' => $e->getMessage(), 'query' => $plan->primaryQuery()]);

            return [];
        }

        return self::mapResults($rows, $plan->contentType);
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
     * mention), scanning the audio or ebook extension set per content type. Returns
     * the lowercased format, or null when none is found.
     */
    private static function deriveFormat(string $title, bool $audio): ?string
    {
        $lower = strtolower($title);
        $extensions = $audio ? AudioFormat::EXTENSIONS : EbookFormat::EXTENSIONS;
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
