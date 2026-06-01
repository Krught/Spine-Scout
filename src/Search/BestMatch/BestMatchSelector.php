<?php

declare(strict_types=1);

namespace App\Search\BestMatch;

use App\Search\Source\ReleaseCandidate;

/**
 * Pure deterministic selector. Given a list of candidates and a policy, returns the
 * best-matching candidate (or null if nothing qualifies).
 *
 * Algorithm:
 *   1. Apply hard gates (allowed formats, min/max size, min seeders, ISBN match).
 *   2. Order survivors by language-priority, then format-priority, then
 *      source-priority (lowest-index hit first), then the configured tie-breaker
 *      chain, stable on original order.
 *   3. pick() returns [0]; rank() returns the whole ordered list.
 *
 * No I/O, no side effects. Safe to call from anywhere. Unit-tested at
 * tests/Search/BestMatch/BestMatchSelectorTest.php.
 */
final class BestMatchSelector
{
    /**
     * @param list<ReleaseCandidate> $candidates
     * @param array<int, bool>       $isbnMatches Optional map of array-key → true when the candidate's
     *                                            metadata matched a planned ISBN. Only consulted when
     *                                            $policy->requireIsbnMatch is true.
     */
    public function pick(
        array $candidates,
        BestMatchPolicy $policy,
        array $isbnMatches = [],
    ): ?ReleaseCandidate {
        return $this->rank($candidates, $policy, $isbnMatches)[0] ?? null;
    }

    /**
     * Like pick(), but returns ALL gate-passing candidates in best-match order
     * (best first) instead of just the winner — used to take the top-N items per
     * source for the download cascade. pick() === rank()[0].
     *
     * @param list<ReleaseCandidate> $candidates
     * @param array<int, bool>       $isbnMatches See pick().
     * @return list<ReleaseCandidate>
     */
    public function rank(
        array $candidates,
        BestMatchPolicy $policy,
        array $isbnMatches = [],
    ): array {
        // Gate, carrying the original index so the sort is stable and the
        // language/format/source ranks can be precomputed once per candidate.
        $rows = [];
        foreach ($candidates as $i => $c) {
            if (!$this->passesGates($c, $policy, $isbnMatches[$i] ?? false)) {
                continue;
            }
            $rows[] = [
                'index'  => $i,
                'c'      => $c,
                'lang'   => $this->priorityRank($c->language, $policy->languagePriority),
                'format' => $this->priorityRank($c->format,   $policy->formatPriority),
                'source' => $this->priorityRank($c->source,   $policy->sourcePriority),
            ];
        }
        if ($rows === []) {
            return [];
        }

        usort($rows, function (array $a, array $b) use ($policy): int {
            return $a['lang'] <=> $b['lang']
                ?: $a['format'] <=> $b['format']
                ?: $a['source'] <=> $b['source']
                ?: $this->compareTieBreakers($a['c'], $b['c'], $policy->tieBreakers)
                ?: $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $row): ReleaseCandidate => $row['c'], $rows);
    }

    private function passesGates(ReleaseCandidate $c, BestMatchPolicy $policy, bool $isbnMatched): bool
    {
        if ($policy->allowedFormats !== []) {
            if ($c->format === null || !in_array(strtolower($c->format), array_map('strtolower', $policy->allowedFormats), true)) {
                return false;
            }
        }
        if ($policy->minSizeBytes !== null && $c->sizeBytes !== null && $c->sizeBytes < $policy->minSizeBytes) {
            return false;
        }
        if ($policy->maxSizeBytes !== null && $c->sizeBytes !== null && $c->sizeBytes > $policy->maxSizeBytes) {
            return false;
        }
        if ($policy->minSeeders !== null && $c->protocol === ReleaseCandidate::PROTOCOL_TORRENT) {
            if (($c->seeders ?? 0) < $policy->minSeeders) {
                return false;
            }
        }
        if ($policy->requireIsbnMatch && !$isbnMatched) {
            return false;
        }
        return true;
    }

    /**
     * @param list<string> $priority
     */
    private function priorityRank(?string $value, array $priority): int
    {
        if ($priority === []) {
            return 0;
        }
        if ($value === null) {
            return PHP_INT_MAX;
        }
        $needle = strtolower($value);
        foreach ($priority as $i => $p) {
            if (strtolower($p) === $needle) {
                return $i;
            }
        }
        return PHP_INT_MAX;
    }

    /**
     * @param list<string> $tieBreakers
     */
    private function compareTieBreakers(ReleaseCandidate $a, ReleaseCandidate $b, array $tieBreakers): int
    {
        foreach ($tieBreakers as $key) {
            $cmp = match ($key) {
                BestMatchPolicy::TIE_MOST_DOWNLOADED => ($b->downloads ?? -1) <=> ($a->downloads ?? -1),
                BestMatchPolicy::TIE_MOST_SEEDERS    => ($b->seeders   ?? -1) <=> ($a->seeders   ?? -1),
                BestMatchPolicy::TIE_LARGEST_SIZE    => ($b->sizeBytes ?? -1) <=> ($a->sizeBytes ?? -1),
                BestMatchPolicy::TIE_SMALLEST_SIZE   => self::ascSize($a->sizeBytes, $b->sizeBytes),
                default                              => 0,
            };
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    private static function ascSize(?int $a, ?int $b): int
    {
        // Unknown size sorts last for smallest-first.
        $av = $a ?? PHP_INT_MAX;
        $bv = $b ?? PHP_INT_MAX;
        return $av <=> $bv;
    }
}
