<?php

declare(strict_types=1);

namespace App\Search\Source\Welib;

use App\Download\Bypass\BypassResolver;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\DirectHttpProtocol\AAStyleResult;
use App\Search\Source\Http\AbstractDirectHttpSource;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Welib release source. Welib is an Anna's-Archive-shaped HTML index (a /search
 * results table keyed by a content hash, plus a /md5/{hash} record page that
 * enumerates download links), so it reuses AAStyleHttpProtocol wholesale — only
 * the brand identity and config id differ.
 *
 * Auto-registered via the app.release_source tag. Reads its own mirror list and
 * enabled flag from DirectDownloadConfig under the 'welib' id.
 */
final class WelibSource extends AbstractDirectHttpSource
{
    public const NAME = 'welib';

    public function __construct(
        SearchSettingsProvider $settings,
        HttpClientInterface $httpClient,
        private readonly AAStyleHttpProtocol $protocol,
        private readonly WelibSearchParser $searchParser,
        private readonly BypassResolver $bypass,
    ) {
        parent::__construct($settings, $httpClient);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function sourceId(): string
    {
        return DirectDownloadSource::Welib->value;
    }

    public function getDisplayName(): string
    {
        return DirectDownloadSource::Welib->label();
    }

    protected function buildSearchUrl(string $base, ReleaseSearchPlan $plan): string
    {
        return $this->protocol->buildSearchUrl($base, $plan);
    }

    protected function parseToCandidates(string $base, string $html): array
    {
        // Welib's /search now returns the AA "book-card" div layout, not the
        // results <table> AAStyleHttpProtocol parses — so search results go
        // through the Welib-specific card parser. (The /md5 record page is still
        // AA-shaped, so resolveDetail/linksVia below stay on the protocol.)
        return array_map(
            fn (AAStyleResult $r): ReleaseCandidate => $this->toCandidate($base, $r),
            $this->searchParser->parseSearchResults($html),
        );
    }

    protected function detailPlan(ReleaseCandidate $candidate, ?DirectDownloadConfig $config): array
    {
        $base = $candidate->extra['mirror'] ?? null;
        if (!is_string($base) || $base === '') {
            return ['url' => null, 'result' => ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'No mirror to resolve detail page.']];
        }

        return ['url' => $this->protocol->buildDownloadsUrl($base, $candidate->sourceId), 'result' => null];
    }

    protected function detailFromResponse(ReleaseCandidate $candidate, array $response, ?DirectDownloadConfig $config): array
    {
        $base = (string) $candidate->extra['mirror'];
        if ($response['error'] !== null) {
            return ['isbns' => [], 'raw' => [], 'links' => [], 'error' => $response['error']];
        }

        $meta = $this->protocol->parseRecordMetadata($response['html']);
        $includeFast = ($config ?? $this->settings->getDirectDownloadConfig())->fastDownloadEnabled;

        return [
            'isbns' => $meta['isbns'],
            'raw'   => $meta['raw'],
            'links' => $this->protocol->parseDownloadLinks($response['html'], $base, $includeFast),
            'error' => null,
        ];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        $response = $this->fetchRecordPage($this->protocol->buildDownloadsUrl($mirror, $item->sourceId));
        if ($response['error'] !== null) {
            return [];
        }
        $includeFast = ($config ?? $this->settings->getDirectDownloadConfig())->fastDownloadEnabled;

        return $this->protocol->parseDownloadLinks($response['html'], $mirror, $includeFast);
    }

    /**
     * A bypassed record page is fetched one navigation at a time through
     * FlareSolverr (a real browser session), so firing a page of them concurrently
     * would queue behind each other anyway — and risks tripping the bypass's own
     * limits. Fall back to the serial loop whenever the bypass is on; plain GETs
     * still batch.
     */
    protected function supportsConcurrentDetail(): bool
    {
        return !$this->bypass->isEnabled();
    }

    protected function fetchDetailPage(string $url): array
    {
        return $this->fetchRecordPage($url);
    }

    /**
     * Fetch a Welib /md5 record page. Welib gates these pages behind the same
     * anti-bot challenge (DDoS-Guard / Cloudflare) as its download endpoints, so
     * a plain GET only gets the challenge body — the "Detail + score" lookup then
     * sees no ISBNs or links. When a bypass (FlareSolverr) is enabled we resolve
     * the page through it first; otherwise, or if it can't clear the page, we fall
     * back to a direct GET (a non-challenged mirror serves the record directly).
     *
     * @return array{html: string, status: int, error: string|null}
     */
    private function fetchRecordPage(string $url): array
    {
        if ($this->bypass->isEnabled()) {
            $html = $this->bypass->fetch($url);
            if (is_string($html) && $html !== '') {
                return ['html' => $html, 'status' => 200, 'error' => null];
            }
        }

        return $this->request($url);
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
            indexer: $this->getDisplayName(),
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
}
