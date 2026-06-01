<?php

declare(strict_types=1);

namespace App\Search\Source\ZLibrary;

use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\SearchSettingsProvider;
use App\Search\Source\Http\AbstractDirectHttpSource;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ZLibraryProtocol\ZLibraryHttpProtocol;
use App\Search\Source\ZLibraryProtocol\ZLibraryResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Z-Library release source: searches a Z-Library-style mirror (<z-bookcard>
 * result cards) and resolves the concrete /dl/ download link from the record's
 * book page, so the download client receives a directly-streamable URL.
 *
 * Auto-registered via the app.release_source tag. Reads its own mirror list and
 * enabled flag from DirectDownloadConfig under the 'zlibrary' id. Z-Library is
 * publicly searchable/downloadable, so no credentials are required.
 */
final class ZLibrarySource extends AbstractDirectHttpSource
{
    public const NAME = 'zlibrary';

    public function __construct(
        SearchSettingsProvider $settings,
        HttpClientInterface $httpClient,
        private readonly ZLibraryHttpProtocol $protocol,
    ) {
        parent::__construct($settings, $httpClient);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function sourceId(): string
    {
        return DirectDownloadSource::ZLibrary->value;
    }

    public function getDisplayName(): string
    {
        return DirectDownloadSource::ZLibrary->label();
    }

    protected function buildSearchUrl(string $base, ReleaseSearchPlan $plan): string
    {
        return $this->protocol->buildSearchUrl($base, $plan);
    }

    protected function parseToCandidates(string $base, string $html): array
    {
        return array_map(
            fn (ZLibraryResult $r): ReleaseCandidate => $this->toCandidate($base, $r),
            $this->protocol->parseSearchResults($html),
        );
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        $base = $candidate->extra['mirror'] ?? null;
        $bookUrl = $candidate->infoUrl;
        if (!is_string($base) || $base === '' || $bookUrl === null) {
            return ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'No book page to resolve download link.'];
        }

        $response = $this->request($bookUrl);
        if ($response['error'] !== null) {
            return ['isbns' => [], 'raw' => [], 'links' => [], 'error' => $response['error']];
        }

        return [
            'isbns' => $this->protocol->parseIsbns($response['html']),
            'raw'   => [],
            'links' => $this->protocol->parseDownloadLinks($response['html'], $base),
            'error' => null,
        ];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        // The book-page path is mirror-independent; re-resolve it against $mirror.
        $path = parse_url((string) $item->infoUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return [];
        }
        $response = $this->request($mirror . $path);
        if ($response['error'] !== null) {
            return [];
        }

        return $this->protocol->parseDownloadLinks($response['html'], $mirror);
    }

    private function toCandidate(string $base, ZLibraryResult $record): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: self::NAME,
            sourceId: $record->id,
            title: $record->title,
            format: $record->format,
            language: $record->language,
            sizeBytes: $record->sizeBytes,
            downloadUrl: null,
            infoUrl: $this->protocol->buildBookUrl($base, $record->bookPath),
            protocol: ReleaseCandidate::PROTOCOL_HTTP,
            indexer: $this->getDisplayName(),
            contentType: ReleaseCandidate::CONTENT_EBOOK,
            author: $record->author,
            publisher: $record->publisher,
            year: $record->year,
            extra: [
                'mirror' => $base,
                'size'   => $record->size,
            ],
        );
    }
}
