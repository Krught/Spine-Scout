<?php

declare(strict_types=1);

namespace App\Download\Torrent;

/** Immutable outcome of one TorrentJobReconciler::reconcile() run. */
final readonly class TorrentReconciliationResult
{
    /**
     * @param list<string> $lines Per-job human-readable outcome, in check order.
     */
    public function __construct(
        public bool $hasClient,
        public int $checked,
        public int $reconciled,
        public int $skipped,
        public int $gone,
        public array $lines,
    ) {
    }
}
