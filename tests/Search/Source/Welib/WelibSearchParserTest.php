<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\Welib;

use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\Welib\WelibSearchParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Welib card-layout search parser, against recorded HTML and
 * a few defensive edge cases (no results, a non-results page).
 */
final class WelibSearchParserTest extends TestCase
{
    public function testParsesCardLayout(): void
    {
        $results = $this->parser()->parseSearchResults($this->fixture('welib_search_results.html'));

        self::assertCount(2, $results);
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $results[0]->id);
        self::assertSame('The Left Hand of Darkness', $results[0]->title);
        self::assertSame('Ursula K. Le Guin', $results[0]->author);
        self::assertSame('epub', $results[0]->format);
        self::assertSame('English', $results[0]->language);
        self::assertSame('1969', $results[0]->year);
        self::assertSame('1.2 MB', $results[0]->size);
        self::assertSame((int) round(1.2 * 1024 ** 2), $results[0]->sizeBytes);
    }

    public function testReturnsEmptyForNoResultsPage(): void
    {
        self::assertSame([], $this->parser()->parseSearchResults('<html><body>No files found.</body></html>'));
    }

    public function testReturnsEmptyWhenNoCardsPresent(): void
    {
        // A challenge/landing page with no result cards must yield nothing, not throw.
        self::assertSame([], $this->parser()->parseSearchResults('<html><body><div class="intro">Just a moment…</div></body></html>'));
        self::assertSame([], $this->parser()->parseSearchResults(''));
    }

    private function parser(): WelibSearchParser
    {
        return new WelibSearchParser(new AAStyleHttpProtocol());
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/responses/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
