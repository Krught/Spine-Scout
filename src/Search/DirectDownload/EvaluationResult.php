<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Source\ReleaseCandidate;

/**
 * The full outcome of DirectDownloadEvaluator::evaluate(): the search query that
 * was run, every scored candidate (with raw detail values), the qualifying
 * subset, and the final best-match pick.
 *
 * Built for the Settings → Development probe but shaped to be the same thing
 * the fulfillment handler consumes.
 *
 * Immutable.
 */
final class EvaluationResult
{
    /**
     * @param list<ScoredCandidate> $scored    All candidates, sorted by score (desc).
     * @param ReleaseCandidate|null $pick       The best-match selection over the qualifying subset (or null).
     */
    public function __construct(
        public readonly ?string $mirror,
        public readonly ?string $url,
        public readonly ?string $unavailableReason,
        public readonly int $threshold,
        public readonly array $scored,
        public readonly ?ReleaseCandidate $pick,
    ) {
    }

    /** @return list<ScoredCandidate> */
    public function qualifying(): array
    {
        return array_values(array_filter($this->scored, static fn (ScoredCandidate $s): bool => $s->qualifies));
    }

    public function qualifyingCount(): int
    {
        return \count($this->qualifying());
    }

    public function totalCount(): int
    {
        return \count($this->scored);
    }

    public static function unavailable(string $reason): self
    {
        return new self(mirror: null, url: null, unavailableReason: $reason, threshold: 0, scored: [], pick: null);
    }
}
