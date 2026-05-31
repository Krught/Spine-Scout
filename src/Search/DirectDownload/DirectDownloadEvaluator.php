<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\Match\MatchScorer;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttp\DirectHttpSource;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;

/**
 * The "parse + score + select" pipeline the operator asked for, and the seam
 * the fulfillment handler reuses.
 *
 * For a search plan it: runs DirectHttpSource (get + mirror cascade), verifies
 * each candidate's ISBNs against its detail page, scores every candidate with
 * MatchScorer, keeps the ones at/above the policy's minMatchScore threshold, and
 * asks BestMatchSelector to make the final format/source-priority + tie-break
 * pick over that qualifying subset.
 *
 * Returns a fully-populated EvaluationResult (every candidate, its score, the
 * raw detail values, the pick) so callers can both act on the pick and show
 * their working.
 */
final class DirectDownloadEvaluator
{
    public function __construct(
        private readonly DirectHttpSource $source,
        private readonly MatchScorer $scorer,
        private readonly BestMatchSelector $selector,
        private readonly SearchSettingsProvider $settings,
    ) {
    }

    public function evaluate(ReleaseSearchPlan $plan): EvaluationResult
    {
        $reason = $this->source->getUnavailableReason();
        if ($reason !== null) {
            return EvaluationResult::unavailable($reason);
        }

        ['mirror' => $mirror, 'url' => $url] = $this->source->searchUrl($plan);

        $policy = $this->settings->getBestMatchPolicy();
        $threshold = $policy->minMatchScore;

        $scored = [];
        foreach ($this->source->search($plan) as $candidate) {
            $base = $this->mirrorFor($candidate, $mirror);
            $detail = $base !== null
                ? $this->source->fetchRecordDetail($base, $candidate->sourceId)
                : ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'No mirror to resolve detail page.'];

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

        // Sort by total score, descending; stable for equal scores (PHP usort is
        // not stable, so carry the original index as the tiebreaker).
        $indexed = array_map(static fn (int $i, ScoredCandidate $s): array => [$i, $s], array_keys($scored), $scored);
        usort($indexed, static function (array $a, array $b): int {
            return $b[1]->score->total <=> $a[1]->score->total ?: $a[0] <=> $b[0];
        });
        $scored = array_map(static fn (array $pair): ScoredCandidate => $pair[1], $indexed);

        $pick = $this->pick($scored, $policy);

        return new EvaluationResult(
            mirror: $mirror,
            url: $url,
            unavailableReason: null,
            threshold: $threshold,
            scored: $scored,
            pick: $pick,
        );
    }

    /**
     * Hand the qualifying candidates to BestMatchSelector, feeding the ISBN-match
     * map from each candidate's score so the policy's requireIsbnMatch gate works.
     *
     * @param list<ScoredCandidate> $scored
     */
    private function pick(array $scored, BestMatchPolicy $policy): ?ReleaseCandidate
    {
        $candidates = [];
        $isbnMatches = [];
        foreach ($scored as $entry) {
            if (!$entry->qualifies) {
                continue;
            }
            $candidates[] = $entry->candidate;
            $isbnMatches[\count($candidates) - 1] = $entry->score->isbnMatched;
        }

        return $candidates === [] ? null : $this->selector->pick($candidates, $policy, $isbnMatches);
    }

    private function mirrorFor(ReleaseCandidate $candidate, ?string $fallback): ?string
    {
        $mirror = $candidate->extra['mirror'] ?? null;

        return is_string($mirror) && $mirror !== '' ? $mirror : $fallback;
    }
}
