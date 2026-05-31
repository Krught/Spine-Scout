<?php

declare(strict_types=1);

namespace App\Search\Source;

/**
 * A single downloadable release returned by a ReleaseSourceInterface.
 * Immutable. Sources fill what they know; everything else is null.
 */
final class ReleaseCandidate
{
    public const PROTOCOL_HTTP    = 'http';
    public const PROTOCOL_TORRENT = 'torrent';
    public const PROTOCOL_NZB     = 'nzb';

    public const CONTENT_EBOOK     = 'ebook';
    public const CONTENT_AUDIOBOOK = 'audiobook';

    /**
     * @param list<string>         $isbns Verified ISBNs for this release (normalized), when known.
     *                                    Search-result rows usually lack ISBNs; these are filled in
     *                                    by a detail-page lookup (DirectHttpSource::fetchRecordDetail).
     * @param array<string, mixed> $extra Source-specific bag
     */
    public function __construct(
        public readonly string $source,
        public readonly string $sourceId,
        public readonly string $title,
        public readonly ?string $format = null,
        public readonly ?string $language = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $downloadUrl = null,
        public readonly ?string $infoUrl = null,
        public readonly ?string $protocol = null,
        public readonly ?string $indexer = null,
        public readonly ?int $seeders = null,
        public readonly ?int $downloads = null,
        public readonly ?string $contentType = null,
        public readonly ?string $author = null,
        public readonly array $isbns = [],
        public readonly ?string $publisher = null,
        public readonly ?string $year = null,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Return a copy with verified ISBNs attached. Used after a detail-page
     * lookup enriches a search-result candidate that had no ISBNs of its own.
     *
     * @param list<string> $isbns
     */
    public function withIsbns(array $isbns): self
    {
        return new self(
            source: $this->source,
            sourceId: $this->sourceId,
            title: $this->title,
            format: $this->format,
            language: $this->language,
            sizeBytes: $this->sizeBytes,
            downloadUrl: $this->downloadUrl,
            infoUrl: $this->infoUrl,
            protocol: $this->protocol,
            indexer: $this->indexer,
            seeders: $this->seeders,
            downloads: $this->downloads,
            contentType: $this->contentType,
            author: $this->author,
            isbns: array_values($isbns),
            publisher: $this->publisher,
            year: $this->year,
            extra: $this->extra,
        );
    }
}
