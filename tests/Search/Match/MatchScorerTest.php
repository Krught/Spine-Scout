<?php

declare(strict_types=1);

namespace App\Tests\Search\Match;

use App\Entity\Book;
use App\Search\Match\CategoryScore;
use App\Search\Match\MatchScore;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use PHPUnit\Framework\TestCase;

final class MatchScorerTest extends TestCase
{
    private MatchScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new MatchScorer();
    }

    public function testPerfectIsbnTitleAuthorScores100(): void
    {
        $plan = $this->plan(isbn: '9780441478125', title: 'The Left Hand of Darkness', author: 'Ursula K. Le Guin');
        $candidate = $this->candidate(
            title: 'The Left Hand of Darkness',
            author: 'Ursula K. Le Guin',
            isbns: ['9780441478125'],
        );

        $score = $this->scorer->score($candidate, $plan);

        self::assertSame(100, $score->total);
        self::assertSame(100, $score->maxPossible); // 40 + 32 + 28, no publisher/year/lang in request
        self::assertTrue($score->isbnMatched);
        self::assertSame(MatchScorer::WEIGHT_ISBN, $this->cat($score, 'isbn')->earned);
    }

    public function testIsbnOnlyDoesNotQualifyAt50(): void
    {
        // ISBN matches but title/author are unrelated.
        $plan = $this->plan(isbn: '9780441478125', title: 'The Left Hand of Darkness', author: 'Ursula K. Le Guin');
        $candidate = $this->candidate(title: 'Something Unrelated Entirely', author: 'Nobody At All', isbns: ['9780441478125']);

        $score = $this->scorer->score($candidate, $plan);

        // 40 of 100 → 40%.
        self::assertSame(40, $score->total);
        self::assertFalse($score->qualifies(50));
    }

    public function testNumericTitleDoesNotCrash(): void
    {
        // An all-digits title ("1984") normalises to a numeric string, which PHP
        // coerces to an int array key inside the scorer — regression guard for the
        // titlePair() type error that produced.
        $plan = $this->plan(isbn: '', title: '1984', author: 'George Orwell');
        $candidate = $this->candidate(title: '1984', author: 'George Orwell');

        $score = $this->scorer->score($candidate, $plan);

        self::assertSame(100, $score->total);
        self::assertSame('exact', $this->cat($score, 'title')->note);
    }

    public function testTitleAndAuthorQualifyWithoutIsbn(): void
    {
        // ISBN is in the request but the candidate's ISBN doesn't match.
        $plan = $this->plan(isbn: '9780441478125', title: 'The Left Hand of Darkness', author: 'Ursula K. Le Guin');
        $candidate = $this->candidate(title: 'The Left Hand of Darkness', author: 'Ursula K. Le Guin', isbns: ['9999999999999']);

        $score = $this->scorer->score($candidate, $plan);

        // (32 + 28) of 100 → 60%.
        self::assertSame(60, $score->total);
        self::assertFalse($score->isbnMatched);
        self::assertTrue($score->qualifies(50));
    }

    public function testFailingASingleCategoryStillQualifiesWhenTheRestMatch(): void
    {
        $plan = $this->plan(
            isbn: '9780441478125',
            title: 'The Left Hand of Darkness',
            author: 'Ursula K. Le Guin',
            publisher: 'Ace Books',
            publishedDate: '1969',
            language: 'en',
        );
        // Everything matches EXCEPT the ISBN.
        $candidate = $this->candidate(
            title: 'The Left Hand of Darkness',
            author: 'Ursula K. Le Guin',
            isbns: ['9999999999999'],
            publisher: 'Ace Books',
            year: '1969',
            language: 'English [en]',
        );

        $score = $this->scorer->score($candidate, $plan);

        // max 115 (40+32+28+6+6+3); earned 75 → 65%.
        self::assertSame(115, $score->maxPossible);
        self::assertSame(65, $score->total);
        self::assertTrue($score->qualifies(50));
    }

    public function testNotSentCategoriesDoNotCountTowardMax(): void
    {
        // Request has no publisher/year/language → those categories are not sent.
        $plan = $this->plan(isbn: '9780441478125', title: 'Dune', author: 'Frank Herbert');
        $candidate = $this->candidate(title: 'Dune', author: 'Frank Herbert', isbns: ['9780441478125'], publisher: 'Ace', year: '1965', language: 'en');

        $score = $this->scorer->score($candidate, $plan);

        self::assertSame(100, $score->maxPossible);
        self::assertFalse($this->cat($score, 'publisher')->sent);
        self::assertFalse($this->cat($score, 'year')->sent);
        self::assertFalse($this->cat($score, 'language')->sent);
    }

    public function testPublisherSubsetMatchGetsFullCredit(): void
    {
        $plan = $this->plan(isbn: '', title: 'Dune', author: 'Frank Herbert', publisher: 'Ace');
        $candidate = $this->candidate(title: 'Dune', author: 'Frank Herbert', publisher: 'Ace Books');

        $pub = $this->cat($this->scorer->score($candidate, $plan), 'publisher');
        self::assertTrue($pub->sent);
        self::assertSame(1.0, $pub->fraction);
        self::assertSame(MatchScorer::WEIGHT_PUBLISHER, $pub->earned);
    }

    public function testYearExactNearAndMismatch(): void
    {
        $exact = $this->cat($this->scorer->score(
            $this->candidate(title: 'Dune', author: 'Herbert', year: '1965'),
            $this->plan(isbn: '', title: 'Dune', author: 'Herbert', publishedDate: '1965'),
        ), 'year');
        self::assertSame(1.0, $exact->fraction);

        $near = $this->cat($this->scorer->score(
            $this->candidate(title: 'Dune', author: 'Herbert', year: '1966'),
            $this->plan(isbn: '', title: 'Dune', author: 'Herbert', publishedDate: '1965'),
        ), 'year');
        self::assertSame(0.5, $near->fraction);

        $off = $this->cat($this->scorer->score(
            $this->candidate(title: 'Dune', author: 'Herbert', year: '2001'),
            $this->plan(isbn: '', title: 'Dune', author: 'Herbert', publishedDate: '1965'),
        ), 'year');
        self::assertSame(0.0, $off->fraction);
    }

    public function testLanguageMatchesViaBracketedIsoCode(): void
    {
        $plan = $this->plan(isbn: '', title: 'Dune', author: 'Herbert', language: 'en');
        $candidate = $this->candidate(title: 'Dune', author: 'Herbert', language: 'English [en]');

        $lang = $this->cat($this->scorer->score($candidate, $plan), 'language');
        self::assertSame(1.0, $lang->fraction);
    }

    public function testTitlePrefixScoresBelowExact(): void
    {
        $plan = $this->plan(isbn: '', title: 'Dune', author: 'Frank Herbert');
        $candidate = $this->candidate(title: 'Dune (Dune Chronicles #1)', author: 'Frank Herbert');

        $title = $this->cat($this->scorer->score($candidate, $plan), 'title');
        self::assertSame(0.8, $title->fraction);
        self::assertSame('prefix', $title->note);
    }

    public function testTotalIsZeroWhenNothingMatches(): void
    {
        $plan = $this->plan(isbn: '9780441478125', title: 'Dune', author: 'Frank Herbert');
        $candidate = $this->candidate(title: 'Wholly Different', author: 'Other Person', isbns: ['1111111111111']);

        self::assertSame(0, $this->scorer->score($candidate, $plan)->total);
    }

    public function testMatchesAgainstBookIsbnsBeyondPlanCandidates(): void
    {
        $book = new Book('test', 'ext', 'Dune');
        $book->setIsbns(['9780441478125']);
        $book->setAuthor('Frank Herbert');
        $plan = new ReleaseSearchPlan(book: $book, isbnCandidates: [], author: 'Frank Herbert', titleVariants: ['Dune']);
        $candidate = $this->candidate(title: 'Dune', author: 'Frank Herbert', isbns: ['9780441478125']);

        self::assertTrue($this->scorer->score($candidate, $plan)->isbnMatched);
    }

    // --- helpers ----------------------------------------------------------

    private function cat(MatchScore $score, string $key): CategoryScore
    {
        foreach ($score->categories as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        self::fail("No category {$key}");
    }

    private function plan(
        string $isbn,
        string $title,
        string $author,
        string $publisher = '',
        string $publishedDate = '',
        string $language = '',
    ): ReleaseSearchPlan {
        $book = new Book('test', 'ext-1', $title);
        if ($author !== '') {
            $book->setAuthor($author);
        }
        if ($isbn !== '') {
            $book->setIsbns([$isbn]);
        }
        if ($publisher !== '') {
            $book->setPublisher($publisher);
        }
        if ($publishedDate !== '') {
            $book->setPublishedDate($publishedDate);
        }
        if ($language !== '') {
            $book->setLanguage($language);
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: $isbn !== '' ? [$isbn] : [],
            author: $author,
            titleVariants: [$title],
        );
    }

    /**
     * @param list<string> $isbns
     */
    private function candidate(
        string $title,
        ?string $author = null,
        array $isbns = [],
        ?string $publisher = null,
        ?string $year = null,
        ?string $language = null,
    ): ReleaseCandidate {
        return new ReleaseCandidate(
            source: 'direct_http',
            sourceId: 'hash-' . md5($title . ($author ?? '')),
            title: $title,
            language: $language,
            format: 'epub',
            author: $author,
            isbns: $isbns,
            publisher: $publisher,
            year: $year,
        );
    }
}
