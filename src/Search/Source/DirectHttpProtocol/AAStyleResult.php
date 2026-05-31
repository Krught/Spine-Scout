<?php

declare(strict_types=1);

namespace App\Search\Source\DirectHttpProtocol;

/**
 * One parsed row from an AA-style indexer search-results table, or one parsed
 * record page. Protocol-level value object — intentionally decoupled from
 * ReleaseCandidate so the protocol can be unit-tested against recorded HTML
 * without dragging in the release-source plumbing. DirectHttpSource maps this
 * onto a ReleaseCandidate.
 *
 * Immutable. The `id` is the indexer's content hash (its stable record key);
 * it is what buildDownloadsUrl() needs to enumerate per-mirror download links.
 */
final class AAStyleResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $author = null,
        public readonly ?string $publisher = null,
        public readonly ?string $year = null,
        public readonly ?string $language = null,
        public readonly ?string $content = null,
        public readonly ?string $format = null,
        public readonly ?string $size = null,
        public readonly ?int $sizeBytes = null,
    ) {
    }
}
