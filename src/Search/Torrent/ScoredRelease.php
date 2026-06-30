<?php

declare(strict_types=1);

namespace App\Search\Torrent;

use App\Search\Source\ReleaseCandidate;

/**
 * A ReleaseCandidate paired with its TorrentMatchScorer result: the final
 * weighted score (0..1) and the per-axis component breakdown. Used to rank
 * releases and to explain the choice in the dev inspector.
 *
 * Immutable.
 */
final class ScoredRelease
{
    /**
     * @param array{match: float, seeders: float, size: float, format: float} $components Per-axis 0..1 scores
     * @param int                                                             $order     Original input index (stable-sort tiebreak)
     */
    public function __construct(
        public readonly ReleaseCandidate $candidate,
        public readonly float $score,
        public readonly array $components,
        public readonly int $order,
    ) {
    }
}
