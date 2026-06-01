<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The "search + score + select" pipeline the operator asked for, and the seam the
 * fulfillment handler reuses.
 *
 * It is a FAILOVER CASCADE across the enabled sources in the operator's
 * indexerPriority order: it tries the highest-priority enabled+available source
 * first, runs that one source's pipeline (search → verify each candidate's ISBNs
 * against its detail page → score with MatchScorer → keep the ones at/above the
 * policy threshold → BestMatchSelector picks). The FIRST source that yields a
 * qualifying pick wins and the cascade stops. Only when a source produces no
 * qualifying match do we fall over to the next source.
 *
 * Returns a fully-populated EvaluationResult: the per-source searches that were
 * considered (in order), every scored candidate from the winning/last source, and
 * the pick — so callers can both act on the pick and show their working.
 */
final class DirectDownloadEvaluator
{
    /**
     * @param iterable<ReleaseSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.release_source')]
        private readonly iterable $sources,
        private readonly ReleaseSourceScorer $scorer,
        private readonly BestMatchSelector $selector,
        private readonly SearchSettingsProvider $settings,
    ) {
    }

    public function evaluate(ReleaseSearchPlan $plan): EvaluationResult
    {
        $config = $this->settings->getDirectDownloadConfig();
        $policy = $this->settings->getBestMatchPolicy();
        $threshold = $policy->minMatchScore;

        $byId = $this->sourcesById();

        $searches = [];
        $consideredAny = false;
        $fallbackScored = [];
        $fallbackSource = null;

        // Walk the operator's priority order; only enabled rows are cascade members.
        foreach ($config->indexerPriority as $row) {
            if (!($row['enabled'] ?? false)) {
                continue;
            }
            $id = $row['id'];
            $source = $byId[$id] ?? null;
            $label = DirectDownloadSource::tryFromId($id)?->label() ?? $id;

            if ($source === null) {
                $searches[] = new SourceSearch($id, $label, null, null, false, 'No adapter for this source.');
                continue;
            }

            $reason = $source->getUnavailableReason();
            if ($reason !== null) {
                $searches[] = new SourceSearch($id, $label, null, null, false, $reason);
                continue;
            }

            $consideredAny = true;
            ['mirror' => $mirror, 'url' => $url] = $source->searchPlanUrl($plan);
            $searches[] = new SourceSearch($id, $label, $mirror, $url, true);

            $scored = $this->scorer->score($source, $plan, $threshold, $config);
            $pick = $this->pick($scored, $policy);

            if ($pick !== null) {
                // First qualifying source wins — stop the cascade.
                return new EvaluationResult(
                    searches: $searches,
                    unavailableReason: null,
                    threshold: $threshold,
                    scored: $scored,
                    pick: $pick,
                    pickedSource: $id,
                );
            }

            // No qualifying match here; remember the first source that at least
            // returned candidates so the result can explain what was looked at.
            if ($fallbackScored === [] && $scored !== []) {
                $fallbackScored = $scored;
                $fallbackSource = $id;
            }
        }

        if (!$consideredAny) {
            return EvaluationResult::unavailable(
                $searches === []
                    ? 'Enable at least one direct-download source with mirrors in Settings → Direct downloads.'
                    : 'No enabled direct-download source is available — check mirrors in Settings → Direct downloads.',
            );
        }

        return new EvaluationResult(
            searches: $searches,
            unavailableReason: null,
            threshold: $threshold,
            scored: $fallbackScored,
            pick: null,
            pickedSource: $fallbackSource,
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

    /**
     * Index the tagged sources by their DirectDownloadSource id so the cascade can
     * resolve each priority row to its adapter.
     *
     * @return array<string, ReleaseSourceInterface>
     */
    private function sourcesById(): array
    {
        $byId = [];
        foreach ($this->sources as $source) {
            $byId[$source->sourceId()] = $source;
        }

        return $byId;
    }
}
