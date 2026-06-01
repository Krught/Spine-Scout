<?php

declare(strict_types=1);

namespace App\Search\Source\LibGenProtocol;

/**
 * One parsed row from a LibGen-style search-results table. Protocol-level value
 * object, decoupled from ReleaseCandidate so the parser can be unit-tested
 * against recorded HTML. LibGenSource maps this onto a ReleaseCandidate.
 *
 * Immutable. The `id` is the record's MD5 content hash (its stable key); it is
 * what buildDownloadsUrl() needs to resolve the get.php download link.
 */
final class LibGenResult
{
    public function __construct(
        public readonly string $id,
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
