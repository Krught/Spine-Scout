<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\LibGenProtocol;

use App\Entity\Book;
use App\Search\Source\LibGenProtocol\LibGenHttpProtocol;
use App\Search\Source\ReleaseSearchPlan;
use PHPUnit\Framework\TestCase;

final class LibGenHttpProtocolTest extends TestCase
{
    public function testBuildsTitleQueryEvenWhenIsbnPresent(): void
    {
        // LibGen's q does not match ISBNs, so the title drives the query even when
        // an ISBN is available; the ISBN/author are NOT in the query string.
        $url = (new LibGenHttpProtocol())->buildSearchUrl('https://lg.test', $this->plan(isbn: '9780441478125'));

        self::assertStringStartsWith('https://lg.test/search.php?', $url);
        self::assertStringContainsString('q=The+Left+Hand+of+Darkness', $url);
        self::assertStringNotContainsString('9780441478125', $url);
        self::assertStringNotContainsString('Guin', $url);
    }

    public function testFallsBackToIsbnWhenNoTitle(): void
    {
        $plan = new ReleaseSearchPlan(
            book: new Book('test', 'ext', ''),
            isbnCandidates: ['9780441478125'],
            author: '',
            titleVariants: [],
        );
        $url = (new LibGenHttpProtocol())->buildSearchUrl('https://lg.test', $plan);

        self::assertStringContainsString('q=9780441478125', $url);
    }

    public function testParsesBookItemCards(): void
    {
        $records = (new LibGenHttpProtocol())->parseSearchResults($this->fixture('libgen_search_results.html'));

        self::assertCount(2, $records);
        $first = $records[0];
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $first->id);
        self::assertSame('The Left Hand of Darkness', $first->title);
        self::assertSame('Ursula K. Le Guin', $first->author);
        self::assertSame('Ace', $first->publisher);
        self::assertSame('epub', $first->format);
        self::assertSame('1969', $first->year);
        self::assertSame('English', $first->language);
        self::assertSame(1024 ** 2, $first->sizeBytes);
        self::assertSame('pdf', $records[1]->format);
    }

    public function testReturnsEmptyForUnexpectedPage(): void
    {
        $protocol = new LibGenHttpProtocol();
        self::assertSame([], $protocol->parseSearchResults('<html><body>Just a moment…</body></html>'));
        self::assertSame([], $protocol->parseSearchResults(''));
    }

    public function testBuildsDetailAndFileUrls(): void
    {
        $protocol = new LibGenHttpProtocol();
        self::assertSame(
            'https://lg.test/book.php?md5=aaaa1111bbbb2222cccc3333dddd4444',
            $protocol->buildDownloadsUrl('https://lg.test', 'aaaa1111bbbb2222cccc3333dddd4444'),
        );
        self::assertSame(
            'https://lg.test/download.php?md5=aaaa1111bbbb2222cccc3333dddd4444',
            $protocol->buildFileUrl('https://lg.test', 'aaaa1111bbbb2222cccc3333dddd4444'),
        );
    }

    public function testParsesIsbnsFromBookPage(): void
    {
        $isbns = (new LibGenHttpProtocol())->parseIsbns($this->fixture('libgen_book_page.html'));

        self::assertContains('9780441478125', $isbns);
        self::assertContains('0441478123', $isbns);
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
