<?php

declare(strict_types=1);

namespace App\Search\Source\LibGen;

use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\SearchSettingsProvider;
use App\Search\Source\Http\AbstractDirectHttpSource;
use App\Search\Source\LibGenProtocol\LibGenHttpProtocol;
use App\Search\Source\LibGenProtocol\LibGenResult;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LibGen release source: searches a LibGen-style mirror (search.php results
 * table) and resolves the concrete get.php download link from the record's
 * ads.php page, so the download client receives a directly-streamable URL.
 *
 * Auto-registered via the app.release_source tag. Reads its own mirror list and
 * enabled flag from DirectDownloadConfig under the 'libgen' id.
 */
final class LibGenSource extends AbstractDirectHttpSource
{
    public const NAME = 'libgen';

    public function __construct(
        SearchSettingsProvider $settings,
        HttpClientInterface $httpClient,
        private readonly LibGenHttpProtocol $protocol,
    ) {
        parent::__construct($settings, $httpClient);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function sourceId(): string
    {
        return DirectDownloadSource::LibGen->value;
    }

    public function getDisplayName(): string
    {
        return DirectDownloadSource::LibGen->label();
    }

    protected function buildSearchUrl(string $base, ReleaseSearchPlan $plan): string
    {
        return $this->protocol->buildSearchUrl($base, $plan);
    }

    protected function parseToCandidates(string $base, string $html): array
    {
        return array_map(
            fn (LibGenResult $r): ReleaseCandidate => $this->toCandidate($base, $r),
            $this->protocol->parseSearchResults($html),
        );
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        $base = $candidate->extra['mirror'] ?? null;
        if (!is_string($base) || $base === '') {
            return ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'No mirror to resolve download link.'];
        }

        // The direct download endpoint redirects straight to the file, so it is
        // the link the client streams. Fetch the book detail page for ISBNs (the
        // search card carries none) to enrich scoring.
        $links = $this->linksVia($candidate, $base, $config);
        $response = $this->request($this->protocol->buildDownloadsUrl($base, $candidate->sourceId));
        if ($response['error'] !== null) {
            // Detail lookup failed, but the download link is still constructible.
            return ['isbns' => [], 'raw' => [], 'links' => $links, 'error' => null];
        }

        return [
            'isbns' => $this->protocol->parseIsbns($response['html']),
            'raw'   => [],
            'links' => $links,
            'error' => null,
        ];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        // download.php?md5= 302-redirects straight to the file — no fetch needed.
        return [$this->protocol->buildFileUrl($mirror, $item->sourceId)];
    }

    private function toCandidate(string $base, LibGenResult $record): ReleaseCandidate
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
                'mirror' => $base,
                'size'   => $record->size,
            ],
        );
    }
}
