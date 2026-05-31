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
 *   2. Bucket survivors by best (lowest-index) language-priority hit; keep best bucket.
 *   3. Bucket by best format-priority hit; keep best bucket.
 *   4. Bucket by best source-priority hit; keep best bucket.
 *   5. Sort survivors by the configured tie-breaker chain. Return [0].
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
        if ($candidates === []) {
            return null;
        }

        $surviving = [];
        foreach ($candidates as $i => $c) {
            if (!$this->passesGates($c, $policy, $isbnMatches[$i] ?? false)) {
                continue;
            }
            $surviving[] = $c;
        }
        if ($surviving === []) {
            return null;
        }

        $surviving = $this->bestBucket($surviving, fn (ReleaseCandidate $c) => $this->priorityRank($c->language, $policy->languagePriority));
        $surviving = $this->bestBucket($surviving, fn (ReleaseCandidate $c) => $this->priorityRank($c->format,   $policy->formatPriority));
        $surviving = $this->bestBucket($surviving, fn (ReleaseCandidate $c) => $this->priorityRank($c->source,   $policy->sourcePriority));

        usort($surviving, fn (ReleaseCandidate $a, ReleaseCandidate $b) => $this->compareTieBreakers($a, $b, $policy->tieBreakers));

        return $surviving[0];
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
     * Keep only the candidates whose rank (per $rankFn) is the lowest seen.
     * Candidates with rank PHP_INT_MAX (no match in the priority list) survive only
     * when *every* candidate has no match — that way an empty priority list is a
     * no-op rather than a filter.
     *
     * @param list<ReleaseCandidate>             $candidates
     * @param callable(ReleaseCandidate): int    $rankFn
     * @return list<ReleaseCandidate>
     */
    private function bestBucket(array $candidates, callable $rankFn): array
    {
        if ($candidates === []) {
            return $candidates;
        }
        $bestRank = PHP_INT_MAX;
        $ranks = [];
        foreach ($candidates as $i => $c) {
            $r = $rankFn($c);
            $ranks[$i] = $r;
            if ($r < $bestRank) {
                $bestRank = $r;
            }
        }
        $out = [];
        foreach ($candidates as $i => $c) {
            if ($ranks[$i] === $bestRank) {
                $out[] = $c;
            }
        }
        return $out;
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
