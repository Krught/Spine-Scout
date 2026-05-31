<?php

declare(strict_types=1);

namespace App\Search\Match;

/**
 * The result of comparing one metadata dimension (ISBN, title, author, …) of a
 * candidate against the request. Immutable.
 *
 * `sent` is true when the request actually carried this datum — only sent
 * categories count toward the achievable maximum, so a request without (say) a
 * publisher doesn't penalise every candidate for "missing" it.
 *
 * `fraction` is 0..1 (1 = perfect match); `earned` is round(weight × fraction).
 */
final class CategoryScore
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $weight,
        public readonly bool $sent,
        public readonly float $fraction,
        public readonly int $earned,
        public readonly string $note,
        public readonly string $planValue = '',
        public readonly string $candidateValue = '',
    ) {
    }
}
