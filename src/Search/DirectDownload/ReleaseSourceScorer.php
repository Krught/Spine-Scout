<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Match\MatchScorer;
use App\Search\Source\BatchDetailResolverInterface;
use App\Search\Source\ReleaseCandidate;
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
    /**
     * How many candidates may have their detail page fetched, per scoring pass.
     *
     * A search page can return 100+ rows and every detail fetch is its own request
     * (up to a 30s timeout), so resolving all of them is what made the interactive
     * search hang. 25 matches InteractiveSearchController::MAX_RESULTS — the most
     * the user is ever shown — and comfortably covers the download cascade's
     * top-3-qualifying pick, so nothing that could be displayed or downloaded is
     * lost by dropping the rest.
     */
    public const DEFAULT_DETAIL_LIMIT = 25;

    public function __construct(
        private readonly MatchScorer $scorer,
    ) {
    }

    /**
     * @param int|null $detailLimit see scoreCandidates()
     *
     * @return list<ScoredCandidate> scored candidates, sorted by total score desc
     *                               (stable on the source's original order)
     */
    public function score(
        ReleaseSourceInterface $source,
        ReleaseSearchPlan $plan,
        int $threshold,
        ?DirectDownloadConfig $config = null,
        ?int $detailLimit = null,
    ): array {
        return $this->scoreCandidates($source, $source->search($plan, $config), $plan, $threshold, $config, $detailLimit);
    }

    /**
     * Score an already-fetched candidate list (the cascade searches per-mirror
     * itself, for logging, then scores the result here).
     *
     * Two passes, because detail resolution is the expensive part:
     *  1. PRELIMINARY — score every candidate on the fields the search page already
     *     gave us (title/author/format/…), no I/O, and keep the best $detailLimit.
     *  2. FINAL — resolve those candidates' details (concurrently where the source
     *     supports it), enrich them with the verified ISBNs and re-score.
     * Candidates outside the cap are dropped: they were never going to be shown or
     * picked, and each one costs a request.
     *
     * @param list<ReleaseCandidate> $candidates
     * @param int|null $detailLimit max candidates whose detail page is fetched;
     *                              null = DEFAULT_DETAIL_LIMIT, 0 or less = no cap
     *
     * @return list<ScoredCandidate>
     */
    public function scoreCandidates(
        ReleaseSourceInterface $source,
        array $candidates,
        ReleaseSearchPlan $plan,
        int $threshold,
        ?DirectDownloadConfig $config = null,
        ?int $detailLimit = null,
    ): array {
        $candidates = $this->capByPreliminaryScore($candidates, $plan, $detailLimit ?? self::DEFAULT_DETAIL_LIMIT);
        $details = $this->resolveDetails($source, $candidates, $config);

        $scored = [];
        foreach ($candidates as $i => $candidate) {
            $detail = $details[$i];

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

    /**
     * The best $limit candidates by preliminary (detail-free) score, kept in the
     * source's original order so the final sort's tiebreak is unchanged.
     *
     * @param list<ReleaseCandidate> $candidates
     *
     * @return list<ReleaseCandidate>
     */
    private function capByPreliminaryScore(array $candidates, ReleaseSearchPlan $plan, int $limit): array
    {
        $candidates = array_values($candidates);
        if ($limit <= 0 || \count($candidates) <= $limit) {
            return $candidates;
        }

        $ranked = [];
        foreach ($candidates as $i => $candidate) {
            $ranked[] = [$i, $this->scorer->score($candidate, $plan)->total];
        }
        // Best score first; original position breaks ties (usort is not stable).
        usort($ranked, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);

        $keep = array_column(\array_slice($ranked, 0, $limit), 0);
        sort($keep);

        return array_map(static fn (int $i): ReleaseCandidate => $candidates[$i], $keep);
    }

    /**
     * Detail for every candidate, batched (and therefore concurrent) when the
     * source supports it. Falls back to the per-candidate call for sources that
     * don't, and for any key a batch implementation failed to return.
     *
     * @param list<ReleaseCandidate> $candidates
     *
     * @return array<int, array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}>
     */
    private function resolveDetails(ReleaseSourceInterface $source, array $candidates, ?DirectDownloadConfig $config): array
    {
        $details = [];
        if ($source instanceof BatchDetailResolverInterface && \count($candidates) > 1) {
            $details = $source->resolveDetails($candidates, $config);
        }

        foreach ($candidates as $i => $candidate) {
            if (!isset($details[$i])) {
                $details[$i] = $source->resolveDetail($candidate, $config);
            }
        }

        return $details;
    }
}
