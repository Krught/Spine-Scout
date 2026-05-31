<?php

declare(strict_types=1);

namespace App\Search\Source\DirectHttp;

use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\DirectHttpProtocol\AAStyleResult;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * First concrete ReleaseSourceInterface: the live direct-download "getter".
 *
 * This is the engine the manual DirectDownloadProbe stood in for. It reads the
 * operator's DirectDownloadConfig, picks the Anna's-Archive search mirrors (the
 * only search-capable source), and cascades across them on failure — building
 * the ISBN-first query, GETting it, parsing the results table, and mapping each
 * row onto a ReleaseCandidate.
 *
 * Because the results table carries no ISBN, fetchRecordDetail() does the
 * per-record detail-page lookup (verified ISBNs + download links). It is kept
 * separate from search() so the expensive N-GET verification pass is opt-in by
 * the caller (DirectDownloadEvaluator does it; a cheap title-only search need
 * not).
 *
 * Auto-registered via the `app.release_source` tag (config/services.yaml
 * _instanceof). Never throws on transport/parse failure — a dead mirror yields
 * nothing so the cascade moves on, matching the project ground rule.
 */
final class DirectHttpSource implements ReleaseSourceInterface
{
    public const NAME = 'direct_http';

    private const TIMEOUT = 30;
    private const MAX_REDIRECTS = 5;
    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (compatible; SpineScout/1.0)',
        'Accept'     => 'text/html',
    ];

    public function __construct(
        private readonly SearchSettingsProvider $settings,
        private readonly AAStyleHttpProtocol $protocol,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Direct download (HTTP)';
    }

    public function isAvailable(): bool
    {
        return $this->getUnavailableReason() === null;
    }

    public function getUnavailableReason(): ?string
    {
        $config = $this->settings->getDirectDownloadConfig();
        $aa = DirectDownloadSource::AnnasArchive->value;

        if (!$config->isIndexerEnabled($aa)) {
            return 'Enable the Anna\'s Archive search source in Settings → Direct downloads.';
        }
        if ($config->mirrorsFor($aa)->toArray() === []) {
            return 'Add at least one Anna\'s Archive mirror in Settings → Direct downloads.';
        }

        return null;
    }

    /**
     * Search the configured Anna's-Archive mirrors, cascading on failure. The
     * first mirror that returns a non-empty results table wins; transport
     * errors, "no results", and challenge/landing pages all fall through to the
     * next mirror.
     *
     * Candidates carry no ISBNs yet — call fetchRecordDetail() (or use
     * DirectDownloadEvaluator) to verify them.
     *
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan): array
    {
        foreach ($this->searchMirrors() as $base) {
            $url = $this->protocol->buildSearchUrl($base, $plan);
            $response = $this->request($url);
            if ($response['error'] !== null || $response['html'] === '') {
                continue;
            }
            $records = $this->protocol->parseSearchResults($response['html']);
            if ($records === []) {
                continue;
            }

            return array_map(fn (AAStyleResult $r): ReleaseCandidate => $this->toCandidate($base, $r), $records);
        }

        return [];
    }

    /**
     * Resolve the search query for one mirror without performing the request.
     * Returns the chosen mirror + URL, or nulls when no search mirror is
     * configured. Mirrors DirectDownloadProbe::searchUrl() so the dev probe and
     * the engine agree on what would be requested.
     *
     * @return array{mirror: string|null, url: string|null}
     */
    public function searchUrl(ReleaseSearchPlan $plan): array
    {
        $mirrors = $this->searchMirrors();
        if ($mirrors === []) {
            return ['mirror' => null, 'url' => null];
        }
        $base = $mirrors[0];

        return ['mirror' => $base, 'url' => $this->protocol->buildSearchUrl($base, $plan)];
    }

    /**
     * Fetch and parse one record's detail page: the verified ISBNs (and the raw
     * label/value metadata), plus the concrete download links the page exposes.
     * This is the per-row inspection the operator drives from the dev probe and
     * the ISBN-verification step the evaluator runs before scoring.
     *
     * @return array{
     *     url: string,
     *     status: int,
     *     error: string|null,
     *     isbns: list<string>,
     *     raw: array<string, list<string>>,
     *     links: list<string>,
     * }
     */
    public function fetchRecordDetail(string $baseUrl, string $hash): array
    {
        $url = $this->protocol->buildDownloadsUrl($baseUrl, $hash);
        $response = $this->request($url);

        if ($response['error'] !== null) {
            return ['url' => $url, 'status' => $response['status'], 'error' => $response['error'], 'isbns' => [], 'raw' => [], 'links' => []];
        }

        $meta = $this->protocol->parseRecordMetadata($response['html']);
        $includeFast = $this->settings->getDirectDownloadConfig()->fastDownloadEnabled;

        return [
            'url'    => $url,
            'status' => $response['status'],
            'error'  => null,
            'isbns'  => $meta['isbns'],
            'raw'    => $meta['raw'],
            'links'  => $this->protocol->parseDownloadLinks($response['html'], $baseUrl, $includeFast),
        ];
    }

    /**
     * Ordered Anna's-Archive search mirrors, or [] when the source is disabled
     * or has none configured.
     *
     * @return list<string>
     */
    private function searchMirrors(): array
    {
        $config = $this->settings->getDirectDownloadConfig();
        $aa = DirectDownloadSource::AnnasArchive->value;
        if (!$config->isIndexerEnabled($aa)) {
            return [];
        }

        return $config->mirrorsFor($aa)->toArray();
    }

    private function toCandidate(string $base, AAStyleResult $record): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: self::NAME,
            sourceId: $record->id,
            title: $record->title,
            format: $record->format,
            language: $record->language,
            sizeBytes: $record->sizeBytes,
            downloadUrl: null,
            infoUrl: $this->protocol->buildDownloadsUrl($base, $record->id),
            protocol: ReleaseCandidate::PROTOCOL_HTTP,
            indexer: DirectDownloadSource::AnnasArchive->label(),
            contentType: ReleaseCandidate::CONTENT_EBOOK,
            author: $record->author,
            publisher: $record->publisher,
            year: $record->year,
            extra: [
                'mirror'  => $base,
                'content' => $record->content,
                'size'    => $record->size,
            ],
        );
    }

    /**
     * Single place the HTTP transport options live. Never throws — failures come
     * back as a structured error so the caller can cascade or render them.
     *
     * @return array{html: string, status: int, error: string|null}
     */
    private function request(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout'       => self::TIMEOUT,
                'max_redirects' => self::MAX_REDIRECTS,
                'headers'       => self::HEADERS,
            ]);

            return ['html' => $response->getContent(false), 'status' => $response->getStatusCode(), 'error' => null];
        } catch (\Throwable $e) {
            return ['html' => '', 'status' => 0, 'error' => $e->getMessage()];
        }
    }
}
