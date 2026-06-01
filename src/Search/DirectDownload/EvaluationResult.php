<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Source\ReleaseCandidate;

/**
 * The full outcome of DirectDownloadEvaluator::evaluate(): the per-source searches
 * that were run (in cascade order), every scored candidate from the source that
 * won the cascade (with raw detail values), the qualifying subset, and the final
 * best-match pick.
 *
 * The automatic workflow fails over across sources in priority order; `searches`
 * records each source considered (including skipped/empty ones) and `pickedSource`
 * names the source whose candidates `scored`/`pick` came from. `scored`/`pick`
 * stay flat (single source) so the fulfillment handler consumes them unchanged.
 *
 * Built for the Settings → Development probe but shaped to be the same thing the
 * fulfillment handler consumes.
 *
 * Immutable.
 */
final class EvaluationResult
{
    /**
     * @param list<SourceSearch>    $searches     Per-source query descriptors, in cascade order.
     * @param list<ScoredCandidate> $scored       Candidates from the winning source, sorted by score (desc).
     * @param ReleaseCandidate|null $pick          The best-match selection over the qualifying subset (or null).
     * @param string|null           $pickedSource  sourceId the scored/pick set came from (null when none qualified).
     */
    public function __construct(
        public readonly array $searches,
        public readonly ?string $unavailableReason,
        public readonly int $threshold,
        public readonly array $scored,
        public readonly ?ReleaseCandidate $pick,
        public readonly ?string $pickedSource = null,
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

    /** The mirror of the source whose candidates were scored, or the first search's. */
    public function firstMirror(): ?string
    {
        foreach ($this->searches as $search) {
            if ($this->pickedSource !== null && $search->sourceId === $this->pickedSource) {
                return $search->mirror;
            }
        }

        return $this->searches[0]->mirror ?? null;
    }

    /** The query URL of the source whose candidates were scored, or the first search's. */
    public function firstUrl(): ?string
    {
        foreach ($this->searches as $search) {
            if ($this->pickedSource !== null && $search->sourceId === $this->pickedSource) {
                return $search->url;
            }
        }

        return $this->searches[0]->url ?? null;
    }

    public static function unavailable(string $reason): self
    {
        return new self(searches: [], unavailableReason: $reason, threshold: 0, scored: [], pick: null);
    }
}
