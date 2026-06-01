<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

/**
 * One source's contribution to an evaluation run: which source it was, the mirror
 * + query URL it would request (or did), and whether it was available. The
 * evaluator records one of these per source it considered, in cascade order, so
 * the dev probe and CLI can show what each source was asked — even the ones that
 * were skipped (disabled / no mirrors) or that yielded nothing.
 *
 * Immutable.
 */
final class SourceSearch
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $label,
        public readonly ?string $mirror,
        public readonly ?string $url,
        public readonly bool $available,
        public readonly ?string $reason = null,
    ) {
    }
}
