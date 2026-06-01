<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;

/**
 * Runs one source's "search → verify each candidate's ISBNs against its detail
 * page → score → sort" pass. Shared by DirectDownloadEvaluator (the dev-probe /
 * CLI diagnostics, single best-pick) and DirectDownloadCascade (the fulfillment
 * download cascade, top-N per source). Keeping it in one place means both agree
 * on how a source's candidates are scored and ordered.
 */
final class ReleaseSourceScorer
{
    public function __construct(
        private readonly MatchScorer $scorer,
    ) {
    }

    /**
     * @return list<ScoredCandidate> scored candidates, sorted by total score desc
     *                               (stable on the source's original order)
     */
    public function score(
        ReleaseSourceInterface $source,
        ReleaseSearchPlan $plan,
        int $threshold,
        ?DirectDownloadConfig $config = null,
    ): array {
        return $this->scoreCandidates($source, $source->search($plan, $config), $plan, $threshold, $config);
    }

    /**
     * Score an already-fetched candidate list (the cascade searches per-mirror
     * itself, for logging, then scores the result here).
     *
     * @param list<ReleaseCandidate> $candidates
     * @return list<ScoredCandidate>
     */
    public function scoreCandidates(
        ReleaseSourceInterface $source,
        array $candidates,
        ReleaseSearchPlan $plan,
        int $threshold,
        ?DirectDownloadConfig $config = null,
    ): array {
        $scored = [];
        foreach ($candidates as $candidate) {
            $detail = $source->resolveDetail($candidate, $config);

            $enriched = $candidate->withIsbns($detail['isbns']);
            $score = $this->scorer->score($enriched, $plan);

            $scored[] = new ScoredCandidate(
                candidate: $enriched,
                score: $score,
                qualifies: $score->qualifies($threshold),
                detailRaw: $detail['raw'],
                detailLinks: $detail['links'],
                detailError: $detail['error'],
            );
        }

        // usort is not stable, so carry the original index as the tiebreaker.
        $indexed = array_map(static fn (int $i, ScoredCandidate $s): array => [$i, $s], array_keys($scored), $scored);
        usort($indexed, static function (array $a, array $b): int {
            return $b[1]->score->total <=> $a[1]->score->total ?: $a[0] <=> $b[0];
        });

        return array_map(static fn (array $pair): ScoredCandidate => $pair[1], $indexed);
    }
}
