<?php

declare(strict_types=1);

namespace App\Search\Match;

use App\Repository\BookRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;

/**
 * Pure, deterministic relevance scorer. Compares a ReleaseCandidate against a
 * ReleaseSearchPlan across several weighted metadata categories and returns a
 * normalised 0–100 score.
 *
 * Weights (relative): ISBN 40, Title 32, Author 28, Publisher 6, Year 6,
 * Language 3. ISBN is the single strongest signal, but Title+Author (60)
 * together outweigh it — so a confident title+author match can qualify without
 * an ISBN, while an ISBN match alone cannot.
 *
 * Only the categories the *request* actually carries count toward the achievable
 * maximum; the final `total` is earned ÷ achievable × 100. That makes the
 * qualifying threshold adapt to how much metadata we had to match on, and means
 * failing any single category still leaves room to clear a sensible threshold.
 *
 * No I/O, no side effects — unit-tested like BestMatchSelector. Title/author
 * heuristics mirror Shelfmark's releaseScoring.ts.
 *
 * NOTE: Series is intentionally absent — the direct-HTTP source exposes no
 * series field to compare against, so scoring it would be a dead category that
 * penalises every candidate. Add it here once a source provides candidate-side
 * series data.
 */
final class MatchScorer
{
    public const WEIGHT_ISBN      = 40;
    public const WEIGHT_TITLE     = 32;
    public const WEIGHT_AUTHOR    = 28;
    public const WEIGHT_PUBLISHER = 6;
    public const WEIGHT_YEAR      = 6;
    public const WEIGHT_LANGUAGE  = 3;

    private const TITLE_PREFIX_FRACTION    = 0.8;
    private const TITLE_SUBSTRING_FRACTION = 0.6;
    private const TITLE_TOKEN_FRACTION     = 0.5;
    private const YEAR_NEAR_FRACTION       = 0.5;

    /** @var list<string> */
    private const STOP_WORDS = ['a', 'an', 'the', 'and', 'or', 'of', 'in', 'to', 'for', 'on', 'at', 'by', 'is'];

    public function score(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): MatchScore
    {
        $categories = [
            $this->isbnCategory($candidate, $plan),
            $this->titleCategory($candidate, $plan),
            $this->authorCategory($candidate, $plan),
            $this->publisherCategory($candidate, $plan),
            $this->yearCategory($candidate, $plan),
            $this->languageCategory($candidate, $plan),
        ];

        $earned = 0;
        $max = 0;
        $isbnMatched = false;
        foreach ($categories as $c) {
            if (!$c->sent) {
                continue;
            }
            $earned += $c->earned;
            $max += $c->weight;
            if ($c->key === 'isbn' && $c->fraction >= 1.0) {
                $isbnMatched = true;
            }
        }

        $total = $max > 0 ? (int) round($earned / $max * 100) : 0;

        return new MatchScore($total, $earned, $max, $isbnMatched, $categories);
    }

    // --- categories -------------------------------------------------------

    private function isbnCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $wanted = [];
        foreach ([...$plan->isbnCandidates, ...$plan->book->getIsbns()] as $raw) {
            $n = BookRepository::normalizeIsbn($raw);
            if ($n !== null) {
                $wanted[$n] = true;
            }
        }
        if ($wanted === []) {
            return $this->notSent('isbn', 'ISBN', self::WEIGHT_ISBN, 'no ISBN in request');
        }

        $matched = [];
        foreach ($candidate->isbns as $raw) {
            $n = BookRepository::normalizeIsbn($raw);
            if ($n !== null && isset($wanted[$n]) && !in_array($n, $matched, true)) {
                $matched[] = $n;
            }
        }
        $fraction = $matched === [] ? 0.0 : 1.0;

        return $this->category(
            'isbn', 'ISBN', self::WEIGHT_ISBN, true, $fraction,
            $matched === [] ? 'no match' : 'matched ' . implode(', ', $matched),
            implode(', ', array_keys($wanted)),
            implode(', ', $candidate->isbns),
        );
    }

    private function titleCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $candidateTitle = $this->normalize($candidate->title);
        $planTitles = [];
        foreach ([...$plan->titleVariants, $plan->book->getTitle()] as $raw) {
            $n = $this->normalize((string) $raw);
            if ($n !== '') {
                $planTitles[$n] = true;
            }
        }
        if ($planTitles === []) {
            return $this->notSent('title', 'Title', self::WEIGHT_TITLE, 'no title in request');
        }
        if ($candidateTitle === '') {
            return $this->category('title', 'Title', self::WEIGHT_TITLE, true, 0.0, 'candidate has no title', implode(' | ', array_keys($planTitles)), '');
        }

        $best = 0.0;
        $bestNote = 'no match';
        foreach (array_keys($planTitles) as $planTitle) {
            // PHP coerces an all-digits array key to int (e.g. a title like
            // "1984"), so cast back to string before the typed comparison.
            [$fraction, $note] = $this->titlePair($candidateTitle, (string) $planTitle);
            if ($fraction > $best) {
                $best = $fraction;
                $bestNote = $note;
            }
        }

        return $this->category('title', 'Title', self::WEIGHT_TITLE, true, $best, $bestNote, implode(' | ', array_keys($planTitles)), $candidateTitle);
    }

    private function authorCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $planAuthors = $this->authorCandidates($plan);
        if ($planAuthors === []) {
            return $this->notSent('author', 'Author', self::WEIGHT_AUTHOR, 'no author in request');
        }
        $candidateAuthor = $this->normalize((string) $candidate->author);
        if ($candidateAuthor === '') {
            return $this->category('author', 'Author', self::WEIGHT_AUTHOR, true, 0.0, 'candidate has no author', implode(' | ', $planAuthors), '');
        }

        $releaseTokens = $this->tokenSet($candidateAuthor);
        $best = 0.0;
        foreach ($planAuthors as $author) {
            $tokens = $this->tokens($author);
            if ($tokens === []) {
                continue;
            }
            $matched = 0;
            foreach ($tokens as $token) {
                if (isset($releaseTokens[$token])) {
                    ++$matched;
                }
            }
            $best = max($best, $matched / \count($tokens));
        }

        $note = $best >= 1.0 ? 'full' : ($best > 0.0 ? 'partial' : 'no match');

        return $this->category('author', 'Author', self::WEIGHT_AUTHOR, true, $best, $note, implode(' | ', $planAuthors), $candidateAuthor);
    }

    private function publisherCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $planPublisher = $this->normalize((string) $plan->book->getPublisher());
        if ($planPublisher === '') {
            return $this->notSent('publisher', 'Publisher', self::WEIGHT_PUBLISHER, 'no publisher in request');
        }
        $candidatePublisher = $this->normalize((string) $candidate->publisher);
        if ($candidatePublisher === '') {
            return $this->category('publisher', 'Publisher', self::WEIGHT_PUBLISHER, true, 0.0, 'candidate has no publisher', $planPublisher, '');
        }

        [$fraction, $note] = $this->setOverlap($planPublisher, $candidatePublisher);

        return $this->category('publisher', 'Publisher', self::WEIGHT_PUBLISHER, true, $fraction, $note, $planPublisher, $candidatePublisher);
    }

    private function yearCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $planYear = $this->extractYear((string) $plan->book->getPublishedDate());
        if ($planYear === null) {
            return $this->notSent('year', 'Year', self::WEIGHT_YEAR, 'no year in request');
        }
        $candidateYear = $this->extractYear((string) $candidate->year);
        if ($candidateYear === null) {
            return $this->category('year', 'Year', self::WEIGHT_YEAR, true, 0.0, 'candidate has no year', (string) $planYear, '');
        }

        $diff = abs($planYear - $candidateYear);
        $fraction = $diff === 0 ? 1.0 : ($diff === 1 ? self::YEAR_NEAR_FRACTION : 0.0);
        $note = $diff === 0 ? 'exact' : ($diff === 1 ? 'off by 1' : 'no match');

        return $this->category('year', 'Year', self::WEIGHT_YEAR, true, $fraction, $note, (string) $planYear, (string) $candidateYear);
    }

    private function languageCategory(ReleaseCandidate $candidate, ReleaseSearchPlan $plan): CategoryScore
    {
        $planLang = $this->languageKey((string) $plan->book->getLanguage());
        if ($planLang === '') {
            return $this->notSent('language', 'Language', self::WEIGHT_LANGUAGE, 'no language in request');
        }
        $candidateLang = $this->languageKey((string) $candidate->language);
        if ($candidateLang === '') {
            return $this->category('language', 'Language', self::WEIGHT_LANGUAGE, true, 0.0, 'candidate has no language', $planLang, '');
        }

        $match = $planLang === $candidateLang
            || (\strlen($planLang) >= 2 && \strlen($candidateLang) >= 2
                && (str_contains($candidateLang, $planLang) || str_contains($planLang, $candidateLang)));

        return $this->category('language', 'Language', self::WEIGHT_LANGUAGE, true, $match ? 1.0 : 0.0, $match ? 'match' : 'no match', $planLang, $candidateLang);
    }

    // --- comparison helpers ----------------------------------------------

    /**
     * @return array{0: float, 1: string} fraction, note
     */
    private function titlePair(string $releaseTitle, string $candidate): array
    {
        if ($releaseTitle === $candidate) {
            return [1.0, 'exact'];
        }

        $strippedRelease = $this->removeStopWords($releaseTitle);
        $strippedCandidate = $this->removeStopWords($candidate);

        if (str_starts_with($releaseTitle, $candidate)
            || ($strippedCandidate !== '' && str_starts_with($strippedRelease, $strippedCandidate))
        ) {
            return [self::TITLE_PREFIX_FRACTION, 'prefix'];
        }
        if (str_contains($releaseTitle, $candidate)
            || ($strippedCandidate !== '' && str_contains($strippedRelease, $strippedCandidate))
        ) {
            return [self::TITLE_SUBSTRING_FRACTION, 'substring'];
        }

        $candidateTokens = array_values(array_filter(
            explode(' ', $strippedCandidate),
            static fn (string $t): bool => \strlen($t) >= 3,
        ));
        if ($candidateTokens === []) {
            return [0.0, 'no match'];
        }
        $releaseTokens = $this->tokenSet($strippedRelease);
        $matched = 0;
        foreach ($candidateTokens as $token) {
            if (isset($releaseTokens[$token])) {
                ++$matched;
            }
        }
        if ($matched === 0) {
            return [0.0, 'no match'];
        }

        return [self::TITLE_TOKEN_FRACTION * ($matched / \count($candidateTokens)), sprintf('tokens %d/%d', $matched, \count($candidateTokens))];
    }

    /**
     * Token-set overlap for short fields like publisher: full credit when either
     * side's tokens are a subset of the other ("Ace" vs "Ace Books"); otherwise
     * the fraction of the request's tokens that appear in the candidate.
     *
     * @return array{0: float, 1: string} fraction, note
     */
    private function setOverlap(string $planValue, string $candidateValue): array
    {
        $planTokens = $this->tokens($planValue);
        $candidateTokens = $this->tokens($candidateValue);
        if ($planTokens === [] || $candidateTokens === []) {
            return [0.0, 'no match'];
        }
        $candidateSet = $this->tokenSet($candidateValue);
        $planSet = $this->tokenSet($planValue);

        $planInCandidate = $this->allIn($planTokens, $candidateSet);
        $candidateInPlan = $this->allIn($candidateTokens, $planSet);
        if ($planInCandidate || $candidateInPlan) {
            return [1.0, $planValue === $candidateValue ? 'exact' : 'subset'];
        }

        $matched = 0;
        foreach ($planTokens as $token) {
            if (isset($candidateSet[$token])) {
                ++$matched;
            }
        }
        if ($matched === 0) {
            return [0.0, 'no match'];
        }

        return [$matched / \count($planTokens), sprintf('tokens %d/%d', $matched, \count($planTokens))];
    }

    /**
     * @param list<string>            $tokens
     * @param array<string, int|true> $set
     */
    private function allIn(array $tokens, array $set): bool
    {
        foreach ($tokens as $token) {
            if (!isset($set[$token])) {
                return false;
            }
        }

        return $tokens !== [];
    }

    /**
     * @return list<string>
     */
    private function authorCandidates(ReleaseSearchPlan $plan): array
    {
        $sources = [$plan->author, (string) $plan->book->getAuthor()];
        foreach (explode(',', $plan->author) as $part) {
            $sources[] = $part;
        }

        $out = [];
        $seen = [];
        foreach ($sources as $source) {
            $normalized = $this->normalize($source);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    private function extractYear(string $value): ?int
    {
        if (preg_match('/(\d{4})/', $value, $m)) {
            $year = (int) $m[1];
            if ($year >= 1000 && $year <= 9999) {
                return $year;
            }
        }

        return null;
    }

    /**
     * Reduce a language label to a comparable key: the bracketed ISO code if
     * present ("English [en]" → "en"), otherwise the normalised text.
     */
    private function languageKey(string $value): string
    {
        if (preg_match('/\[([a-z]{2,3})\]/i', $value, $m)) {
            return strtolower($m[1]);
        }

        return $this->normalize($value);
    }

    // --- normalisation primitives ----------------------------------------

    private function normalize(string $value): string
    {
        $lower = strtolower($value);
        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? '');
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        return array_values(array_filter(explode(' ', $value), static fn (string $t): bool => $t !== ''));
    }

    /** @return array<string, int> */
    private function tokenSet(string $value): array
    {
        return array_flip($this->tokens($value));
    }

    private function removeStopWords(string $text): string
    {
        $tokens = array_filter(
            explode(' ', $text),
            static fn (string $t): bool => $t !== '' && !in_array($t, self::STOP_WORDS, true),
        );

        return implode(' ', $tokens);
    }

    // --- CategoryScore factories -----------------------------------------

    private function category(
        string $key,
        string $label,
        int $weight,
        bool $sent,
        float $fraction,
        string $note,
        string $planValue = '',
        string $candidateValue = '',
    ): CategoryScore {
        return new CategoryScore(
            key: $key,
            label: $label,
            weight: $weight,
            sent: $sent,
            fraction: $fraction,
            earned: $sent ? (int) round($weight * $fraction) : 0,
            note: $note,
            planValue: $planValue,
            candidateValue: $candidateValue,
        );
    }

    private function notSent(string $key, string $label, int $weight, string $note): CategoryScore
    {
        return $this->category($key, $label, $weight, false, 0.0, $note);
    }
}
