<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Match\MatchScore;
use App\Search\Source\ReleaseCandidate;

/**
 * One ReleaseCandidate paired with its relevance MatchScore and the raw
 * detail-page values it was scored against. Carries everything the Settings →
 * Development probe needs to render a row and explain its score.
 *
 * Immutable.
 */
final class ScoredCandidate
{
    /**
     * @param array<string, list<string>> $detailRaw  Raw label → values parsed from the detail page.
     * @param list<string>                 $detailLinks Concrete download links found on the detail page.
     */
    public function __construct(
        public readonly ReleaseCandidate $candidate,
        public readonly MatchScore $score,
        public readonly bool $qualifies,
        public readonly array $detailRaw = [],
        public readonly array $detailLinks = [],
        public readonly ?string $detailError = null,
    ) {
    }
}
