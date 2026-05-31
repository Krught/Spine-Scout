<?php

declare(strict_types=1);

namespace App\Search\Match;

/**
 * The relevance breakdown for one ReleaseCandidate scored against a
 * ReleaseSearchPlan across several weighted metadata categories (ISBN, title,
 * author, publisher, year, language).
 *
 * `total` is normalised to 0–100: earned points as a percentage of what was
 * *achievable* given the categories the request actually carried. That makes the
 * qualifying threshold adapt automatically to how much metadata we had to match
 * on — a request with only ISBN+title+author is judged out of those three, not
 * penalised for lacking a publisher.
 *
 * Immutable. Carries the per-category breakdown so the dev probe can show
 * exactly how each point was earned.
 *
 * @see MatchScorer
 */
final class MatchScore
{
    /**
     * @param int                  $total      Normalised 0–100 (earned / maxPossible × 100).
     * @param int                  $earned     Raw points earned across sent categories.
     * @param int                  $maxPossible Sum of weights of the categories the request carried.
     * @param bool                 $isbnMatched True when a planned ISBN matched a candidate ISBN.
     * @param list<CategoryScore>  $categories Per-category breakdown (includes not-sent ones for display).
     */
    public function __construct(
        public readonly int $total,
        public readonly int $earned,
        public readonly int $maxPossible,
        public readonly bool $isbnMatched,
        public readonly array $categories,
    ) {
    }

    public function qualifies(int $threshold): bool
    {
        return $this->total >= $threshold;
    }

    /** Sent categories only (those that counted toward the score). */
    public function sentCategories(): array
    {
        return array_values(array_filter($this->categories, static fn (CategoryScore $c): bool => $c->sent));
    }
}
