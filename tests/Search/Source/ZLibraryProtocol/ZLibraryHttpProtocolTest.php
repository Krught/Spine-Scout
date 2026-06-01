<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\ZLibraryProtocol;

use App\Entity\Book;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ZLibraryProtocol\ZLibraryHttpProtocol;
use PHPUnit\Framework\TestCase;

final class ZLibraryHttpProtocolTest extends TestCase
{
    public function testBuildsSearchUrlInThePath(): void
    {
        $url = (new ZLibraryHttpProtocol())->buildSearchUrl('https://z.test', $this->plan(isbn: '9780441478125'));

        self::assertSame('https://z.test/s/9780441478125', $url);
    }

    public function testBuildsSearchUrlWhenIsbnCandidateIsAnInteger(): void
    {
        // Regression: a 13-digit numeric ISBN can reach the plan as an int (PHP
        // coerces numeric-string array keys to ints). rawurlencode() rejects ints,
        // so buildSearchUrl must coerce back to string rather than throw.
        $plan = new ReleaseSearchPlan(
            book: new Book('t', 'e', 'X'),
            isbnCandidates: [9798217190065],
            author: '',
            titleVariants: ['X'],
        );

        self::assertSame('https://z.test/s/9798217190065', (new ZLibraryHttpProtocol())->buildSearchUrl('https://z.test', $plan));
    }

    public function testBuildsKeywordSearchUrlWithoutIsbn(): void
    {
        $url = (new ZLibraryHttpProtocol())->buildSearchUrl('https://z.test', $this->plan(isbn: ''));

        self::assertStringStartsWith('https://z.test/s/', $url);
        self::assertStringContainsString('Le%20Guin', $url);
    }

    public function testParsesBookcards(): void
    {
        $records = (new ZLibraryHttpProtocol())->parseSearchResults($this->fixture('zlib_search_results.html'));

        self::assertCount(2, $records);
        $first = $records[0];
        self::assertSame('1001/abcd12', $first->id);
        self::assertSame('/book/1001/abcd12/the-left-hand-of-darkness.html', $first->bookPath);
        self::assertSame('The Left Hand of Darkness', $first->title);
        self::assertSame('Ursula K. Le Guin', $first->author);
        self::assertSame('epub', $first->format);
        self::assertSame('1969', $first->year);
        self::assertSame('english', $first->language);
        self::assertNotNull($first->sizeBytes);
        self::assertSame('pdf', $records[1]->format);
    }

    public function testReturnsEmptyForUnexpectedPage(): void
    {
        $protocol = new ZLibraryHttpProtocol();
        self::assertSame([], $protocol->parseSearchResults('<html><body>Please log in</body></html>'));
        self::assertSame([], $protocol->parseSearchResults(''));
    }

    public function testParsesDownloadLinkFromBookPage(): void
    {
        $links = (new ZLibraryHttpProtocol())->parseDownloadLinks($this->fixture('zlib_book_page.html'), 'https://z.test');

        self::assertContains('https://z.test/dl/1001/abcd12?dsource=recommend', $links);
    }

    public function testParsesIsbnsFromBookPage(): void
    {
        $isbns = (new ZLibraryHttpProtocol())->parseIsbns($this->fixture('zlib_book_page.html'));

        self::assertContains('9780441478125', $isbns);
        self::assertContains('0441478123', $isbns);
    }

    public function testBuildsAbsoluteBookUrl(): void
    {
        self::assertSame(
            'https://z.test/book/1001/abcd12/x.html',
            (new ZLibraryHttpProtocol())->buildBookUrl('https://z.test', '/book/1001/abcd12/x.html'),
        );
    }

    private function plan(string $isbn): ReleaseSearchPlan
    {
        return new ReleaseSearchPlan(
            book: new Book('test', 'ext', 'The Left Hand of Darkness'),
            isbnCandidates: $isbn !== '' ? [$isbn] : [],
            author: 'Ursula K. Le Guin',
            titleVariants: ['The Left Hand of Darkness'],
        );
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/responses/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
