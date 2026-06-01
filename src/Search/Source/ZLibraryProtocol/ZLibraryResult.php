<?php

declare(strict_types=1);

namespace App\Search\Source\ZLibraryProtocol;

/**
 * One parsed Z-Library search-result card. Protocol-level value object, decoupled
 * from ReleaseCandidate so the parser can be unit-tested against recorded HTML.
 * ZLibrarySource maps this onto a ReleaseCandidate.
 *
 * Immutable. `id` is Z-Library's own book key (derived from the book-page href);
 * `bookPath` is the relative book-page URL used to resolve the download link.
 */
final class ZLibraryResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $bookPath,
        public readonly string $title,
        public readonly ?string $author = null,
        public readonly ?string $publisher = null,
        public readonly ?string $year = null,
        public readonly ?string $language = null,
        public readonly ?string $format = null,
        public readonly ?string $size = null,
        public readonly ?int $sizeBytes = null,
    ) {
    }
}
