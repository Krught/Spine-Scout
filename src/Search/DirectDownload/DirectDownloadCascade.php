<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Download\FulfillmentLog;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The fulfillment download cascade. Walks the operator's enabled sources in
 * priority order; for each it searches + scores, takes the TOP-3 qualifying items
 * (score ≥ the best-match threshold, ordered by the full best-match policy), and
 * for EACH of that source's mirror URLs yields a download attempt per item:
 *
 *   source 1 → mirror 1 → (item1, item2, item3)
 *            → mirror 2 → (item1, item2, item3) → …
 *   source 2 → mirror 1 → …
 *
 * A source with no qualifying item is skipped; with fewer than 3, only that many
 * are tried. The consumer (ProcessDownloadJobHandler) tries each attempt's links
 * and STOPS at the first that streams a file — because this is a lazy generator,
 * sources after the successful one are never searched or scored.
 *
 * On a per-mirror retry the item is re-resolved against that mirror via
 * ReleaseSourceInterface::linksVia(); for the mirror the item was found on we
 * reuse the links already resolved during scoring, avoiding a redundant fetch.
 */
final class DirectDownloadCascade
{
    /** Top qualifying items tried per source. */
    private const TOP_N = 3;

    /**
     * @param iterable<ReleaseSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.release_source')]
        private readonly iterable $sources,
        private readonly ReleaseSourceScorer $scorer,
        private readonly BestMatchSelector $selector,
        private readonly SearchSettingsProvider $settings,
        private readonly FulfillmentLog $log,
    ) {
    }

    /**
     * @param string $subject Activity-log subject (the book title) for the search
     *                        lines this emits as it walks each source.
     * @return iterable<DownloadAttempt>
     */
    public function attempts(ReleaseSearchPlan $plan, string $subject = ''): iterable
    {
        $config = $this->settings->getDirectDownloadConfig();
        $policy = $this->settings->getBestMatchPolicy();
        $threshold = $policy->minMatchScore;
        $byId = $this->sourcesById();
        $subject = $subject !== '' ? $subject : null;

        foreach ($config->indexerPriority as $row) {
            if (!($row['enabled'] ?? false)) {
                // Disabled by the operator — intentional, so don't log noise.
                continue;
            }
            $id = $row['id'];
            // Torrent isn't an HTTP mirror source — it's fulfilled out-of-band by the
            // torrent pipeline (ProcessDownloadJobHandler handles it around this cascade).
            if (DirectDownloadSource::tryFromId($id)?->usesMirrors() === false) {
                continue;
            }
            $label = DirectDownloadSource::tryFromId($id)?->label() ?? $id;
            $source = $byId[$id] ?? null;

            if ($source === null) {
                $this->log->warn(sprintf('%s — no adapter; skipped', $label), $subject);
                continue;
            }
            // Enabled but not usable (e.g. no mirrors): say so instead of skipping
            // silently — otherwise the operator can't tell why it wasn't checked.
            $reason = $source->getUnavailableReason();
            if ($reason !== null) {
                $this->log->info(sprintf('%s — skipped: %s', $label, $reason), $subject);
                continue;
            }

            $mirrors = $config->mirrorsFor($id)->toArray();

            // Search each mirror (logged), first non-empty wins. Wrapped so a
            // throwing source logs and the cascade moves on rather than aborting.
            try {
                $candidates = [];
                $count = \count($mirrors);
                foreach ($mirrors as $i => $mirror) {
                    $this->log->info(
                        sprintf('Searching %s [mirror %d/%d]: %s', $label, $i + 1, $count, $source->searchUrlFor($mirror, $plan)),
                        $subject,
                    );
                    $candidates = $source->searchVia($mirror, $plan, $config);
                    if ($candidates !== []) {
                        break;
                    }
                }
                if ($candidates === []) {
                    $this->log->info(sprintf('%s — no results from %d mirror(s)', $label, $count), $subject);
                    continue;
                }

                $scored = $this->scorer->scoreCandidates($source, $candidates, $plan, $threshold, $config);
                $top = $this->topQualifying($scored, $policy);
                if ($top === []) {
                    $this->log->info(sprintf('%s — %d found, none qualified', $label, \count($scored)), $subject);
                    continue;
                }
            } catch (\Throwable $e) {
                $this->log->warn(sprintf('%s — error: %s', $label, $e->getMessage()), $subject);
                continue;
            }

            $this->log->info(
                sprintf('%s — %d match(es) qualify; trying download across %d mirror(s)', $label, \count($top), \count($mirrors)),
                $subject,
            );

            // Try each top-N item against every mirror (source → mirror → item).
            foreach ($mirrors as $mirror) {
                foreach ($top as $entry) {
                    $links = $this->linksFor($source, $entry, $mirror, $config);
                    if ($links !== []) {
                        yield new DownloadAttempt($id, $entry->candidate, $mirror, $links);
                    }
                }
            }
        }
    }

    /**
     * Best-match-ordered, threshold-qualifying candidates, capped at TOP_N. The
     * ISBN-match map is fed from each candidate's score so the policy's
     * requireIsbnMatch gate works (mirrors DirectDownloadEvaluator::pick()).
     *
     * @param list<ScoredCandidate> $scored
     * @return list<ScoredCandidate>
     */
    private function topQualifying(array $scored, BestMatchPolicy $policy): array
    {
        $candidates = [];
        $isbnMatches = [];
        $byKey = [];
        foreach ($scored as $entry) {
            if (!$entry->qualifies) {
                continue;
            }
            $candidates[] = $entry->candidate;
            $isbnMatches[\count($candidates) - 1] = $entry->score->isbnMatched;
            $byKey[$this->key($entry->candidate)] = $entry;
        }
        if ($candidates === []) {
            return [];
        }

        $ranked = $this->selector->rank($candidates, $policy, $isbnMatches);

        $out = [];
        foreach ($ranked as $candidate) {
            $entry = $byKey[$this->key($candidate)] ?? null;
            if ($entry !== null) {
                $out[] = $entry;
            }
            if (\count($out) >= self::TOP_N) {
                break;
            }
        }

        return $out;
    }

    /**
     * Links for $entry's item via $mirror. Reuse the links resolved during scoring
     * when $mirror is the mirror the item was found on (no extra request);
     * otherwise re-resolve against $mirror.
     *
     * @return list<string>
     */
    private function linksFor(ReleaseSourceInterface $source, ScoredCandidate $entry, string $mirror, DirectDownloadConfig $config): array
    {
        $foundMirror = $entry->candidate->extra['mirror'] ?? null;
        if (is_string($foundMirror) && $foundMirror === $mirror && $entry->detailLinks !== []) {
            return $entry->detailLinks;
        }

        return $source->linksVia($entry->candidate, $mirror, $config);
    }

    private function key(ReleaseCandidate $c): string
    {
        return $c->source . '|' . $c->sourceId;
    }

    /** @return array<string, ReleaseSourceInterface> */
    private function sourcesById(): array
    {
        $byId = [];
        foreach ($this->sources as $source) {
            $byId[$source->sourceId()] = $source;
        }

        return $byId;
    }
}
