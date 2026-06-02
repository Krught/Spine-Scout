<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\DirectHttpProtocol;

use App\Entity\Book;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseSearchPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AAStyleHttpProtocolTest extends TestCase
{
    private AAStyleHttpProtocol $protocol;

    protected function setUp(): void
    {
        $this->protocol = new AAStyleHttpProtocol();
    }

    // --- URL construction -------------------------------------------------

    public function testBuildSearchUrlIsIsbnFirstWhenIsbnPresent(): void
    {
        $plan = $this->plan(
            isbns: ['9780441478125'],
            title: 'The Left Hand of Darkness',
            author: 'Ursula K. Le Guin',
        );

        $url = $this->protocol->buildSearchUrl('https://mirror.invalid', $plan);

        self::assertStringStartsWith('https://mirror.invalid/search?', $url);
        // ISBN expression is present and url-encoded.
        self::assertStringContainsString(rawurlencode("('isbn13:9780441478125' || 'isbn10:9780441478125')"), $url);
        // The title+author keyword tags along as a relevance hint.
        self::assertStringContainsString(rawurlencode('The Left Hand of Darkness Ursula K. Le Guin'), $url);
        // ISBN path does NOT add structured term filters.
        self::assertStringNotContainsString('termtype_', $url);
    }

    public function testBuildSearchUrlCapsIsbnCandidatesInQuery(): void
    {
        // 25 ISBNs in the plan, but only the first 20 are embedded in the query
        // (the full set still verifies the match downstream). ISBNs are 13-digit
        // numeric strings so they survive normalisation.
        $isbns = [];
        for ($i = 0; $i < 25; ++$i) {
            $isbns[] = sprintf('978000000%04d', $i);
        }
        $plan = $this->plan(isbns: $isbns, title: 'Capped', author: 'Author');

        $url = $this->protocol->buildSearchUrl('https://mirror.invalid', $plan);

        // First and 20th ISBN are present; the 21st is dropped from the query.
        self::assertStringContainsString(rawurlencode("'isbn13:9780000000000'"), $url);
        self::assertStringContainsString(rawurlencode("'isbn13:9780000000019'"), $url);
        self::assertStringNotContainsString(rawurlencode("'isbn13:9780000000020'"), $url);
    }

    public function testBuildSearchUrlFallsBackToTitleAuthorWhenNoIsbn(): void
    {
        $plan = $this->plan(
            isbns: [],
            title: 'Dune',
            author: 'Frank Herbert',
        );

        $url = $this->protocol->buildSearchUrl('https://mirror.invalid', $plan);

        self::assertStringContainsString('q=' . rawurlencode('Dune Frank Herbert'), $url);
        self::assertStringContainsString('termtype_1=title&termval_1=' . rawurlencode('Dune'), $url);
        self::assertStringContainsString('termtype_2=author&termval_2=' . rawurlencode('Frank Herbert'), $url);
        self::assertStringNotContainsString('isbn13:', $url);
    }

    public function testBuildSearchUrlAdvertisesFormatsAndLanguages(): void
    {
        $plan = $this->plan(isbns: [], title: 'Dune', author: 'Frank Herbert', languages: ['en', 'de']);

        $url = $this->protocol->buildSearchUrl('https://mirror.invalid', $plan, ['epub', 'pdf']);

        self::assertStringContainsString('&ext=epub', $url);
        self::assertStringContainsString('&ext=pdf', $url);
        self::assertStringNotContainsString('&ext=mobi', $url);
        self::assertStringContainsString('&lang=en', $url);
        self::assertStringContainsString('&lang=de', $url);
    }

    public function testBuildSearchUrlSkipsBlankAndAllLanguageFilters(): void
    {
        $plan = $this->plan(isbns: ['9780441478125'], title: 'X', author: 'Y', languages: ['all', '']);
        $url = $this->protocol->buildSearchUrl('https://mirror.invalid', $plan);
        self::assertStringNotContainsString('&lang=', $url);
    }

    // --- search result parsing -------------------------------------------

    public function testParseSearchResultsExtractsRecords(): void
    {
        $results = $this->protocol->parseSearchResults($this->fixture('aa_search_results.html'));

        // Two valid rows; the ad row and the truncated row are dropped.
        self::assertCount(2, $results);

        $first = $results[0];
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $first->id);
        self::assertSame('The Left Hand of Darkness', $first->title);
        self::assertSame('Ursula K. Le Guin', $first->author);
        self::assertSame('Ace Books', $first->publisher);
        self::assertSame('1969', $first->year);
        self::assertSame('English [en]', $first->language);
        self::assertSame('book (fiction)', $first->content);
        self::assertSame('epub', $first->format);
        self::assertSame('1.2 MB', $first->size);
        self::assertSame((int) round(1.2 * 1024 * 1024), $first->sizeBytes);

        self::assertSame('pdf', $results[1]->format);
        self::assertSame((int) round(14.7 * 1024 * 1024), $results[1]->sizeBytes);
    }

    public function testParseSearchResultsReturnsEmptyForNoResultsPage(): void
    {
        self::assertSame([], $this->protocol->parseSearchResults($this->fixture('aa_no_results.html')));
    }

    public function testParseSearchResultsReturnsEmptyForUnexpectedHtml(): void
    {
        // A challenge/landing page with no results table must not throw — it
        // yields nothing so the source can fail over to the next mirror.
        self::assertSame([], $this->protocol->parseSearchResults('<html><body><p>Just a moment…</p></body></html>'));
        self::assertSame([], $this->protocol->parseSearchResults(''));
    }

    // --- download link parsing -------------------------------------------

    public function testBuildDownloadsUrl(): void
    {
        self::assertSame(
            'https://mirror.invalid/md5/aaaa1111',
            $this->protocol->buildDownloadsUrl('https://mirror.invalid', 'aaaa1111'),
        );
    }

    public function testParseDownloadLinksResolvesAndDedupes(): void
    {
        $links = $this->protocol->parseDownloadLinks($this->fixture('aa_md5_page.html'), 'https://mirror.invalid');

        self::assertSame([
            'https://mirror.invalid/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/0',
            'https://mirror.invalid/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/1',
            'https://mirror.example.invalid/get.php?md5=aaaa1111bbbb2222cccc3333dddd4444&key=abc123',
        ], $links);
        // Fast-partner link excluded by default; /login is not a download link;
        // the duplicate slow link is deduped.
        self::assertNotContains('https://mirror.invalid/fast_download/aaaa1111bbbb2222cccc3333dddd4444/0/0', $links);
        self::assertNotContains('https://mirror.invalid/login', $links);
    }

    public function testParseDownloadLinksIncludesFastWhenEnabled(): void
    {
        $links = $this->protocol->parseDownloadLinks($this->fixture('aa_md5_page.html'), 'https://mirror.invalid', includeFast: true);

        // Fast-partner link is now present, ahead of the slow ones (document order).
        self::assertSame([
            'https://mirror.invalid/fast_download/aaaa1111bbbb2222cccc3333dddd4444/0/0',
            'https://mirror.invalid/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/0',
            'https://mirror.invalid/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/1',
            'https://mirror.example.invalid/get.php?md5=aaaa1111bbbb2222cccc3333dddd4444&key=abc123',
        ], $links);
        // The "...account for fast downloads" /login anchor must NOT be mistaken for a fast link.
        self::assertNotContains('https://mirror.invalid/login', $links);
    }

    // --- detail-page metadata parsing ------------------------------------

    public function testParseRecordMetadataExtractsAndNormalizesIsbns(): void
    {
        $meta = $this->protocol->parseRecordMetadata($this->fixture('aa_record_detail.html'));

        // ISBN-13 rows first (document order), then ISBN-10; hyphens stripped,
        // deduped, ASIN ignored (not a valid ISBN length).
        self::assertSame(['9780441478125', '9780199536832', '0441478123'], $meta['isbns']);

        // Raw label/value map keeps what was seen for the dev display; the
        // "filename" row is dropped (not a metadata label we surface).
        self::assertSame(['978-0-441-47812-5', '9780199536832'], $meta['raw']['isbn-13']);
        self::assertSame(['0441478123'], $meta['raw']['isbn-10']);
        self::assertSame(['English [en]'], $meta['raw']['language']);
        self::assertSame(['1969'], $meta['raw']['year']);
        self::assertArrayHasKey('asin', $meta['raw']);
        self::assertArrayNotHasKey('filename', $meta['raw']);
    }

    public function testParseRecordMetadataIgnoresOuterContainersAndYieldsNothingWithoutCodes(): void
    {
        // The md5 page has a title block + download list but no codes table.
        $meta = $this->protocol->parseRecordMetadata($this->fixture('aa_md5_page.html'));
        self::assertSame([], $meta['isbns']);
        self::assertSame([], $meta['raw']);
    }

    public function testParseRecordMetadataIsEmptyForEmptyHtml(): void
    {
        self::assertSame(['isbns' => [], 'raw' => []], $this->protocol->parseRecordMetadata(''));
    }

    public function testParseRecordMetadataFallsBackToWholePageIsbnScan(): void
    {
        // No labelled rows, but an ISBN appears in free text — the fallback scan
        // still recovers it.
        $html = '<html><body><p>Some edition, ISBN 978 0 441 47812 5 in the blurb.</p></body></html>';
        $meta = $this->protocol->parseRecordMetadata($html);
        self::assertSame(['9780441478125'], $meta['isbns']);
        self::assertSame([], $meta['raw']);
    }

    // --- size parsing edge cases -----------------------------------------

    #[DataProvider('sizeProvider')]
    public function testParseSize(string $input, ?int $expected): void
    {
        self::assertSame($expected, $this->protocol->parseSize($input));
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function sizeProvider(): iterable
    {
        yield 'megabytes'        => ['5.2 MB', (int) round(5.2 * 1024 * 1024)];
        yield 'kilobytes'        => ['812 KB', 812 * 1024];
        yield 'gigabytes'        => ['1.3 GB', (int) round(1.3 * 1024 * 1024 * 1024)];
        yield 'bytes'            => ['900 B', 900];
        yield 'thousands comma'  => ['1,024 KB', 1024 * 1024];
        yield 'no space'         => ['3.4MB', (int) round(3.4 * 1024 * 1024)];
        yield 'unparseable'      => ['unknown', null];
        yield 'empty'            => ['', null];
    }

    // --- partner-download URL (slow-download interstitial) ----------------

    public function testParsePartnerDownloadUrlFromAnchor(): void
    {
        $html = <<<'HTML'
            <html><body>
              <p>Slow Partner Server #8</p>
              <a href="/md5/7e5bdba3">Back</a>
              <a href="http://45.3.63.28:6060/d3/y/file.pdf~/vyKQ/book.pdf">📚 Download now</a>
              <a href="https://annas-archive.gl/donate">become a member</a>
            </body></html>
            HTML;

        $url = $this->protocol->parsePartnerDownloadUrl($html, 'https://annas-archive.gl/slow_download/7e5bdba3/0/7');

        self::assertSame('http://45.3.63.28:6060/d3/y/file.pdf~/vyKQ/book.pdf', $url);
    }

    public function testParsePartnerDownloadUrlFromCopyableText(): void
    {
        // Modern interstitial renders the URL as copyable text, not a link.
        $html = '<p>To download, copy this URL: http://45.3.63.28:6060/d3/y/book.pdf and paste it.</p>';

        $url = $this->protocol->parsePartnerDownloadUrl($html, 'https://annas-archive.se/slow_download/abc/0/2');

        self::assertSame('http://45.3.63.28:6060/d3/y/book.pdf', $url);
    }

    public function testParsePartnerDownloadUrlIgnoresMirrorAndNavLinks(): void
    {
        // A countdown / browser-check page with only AA-site links yields null,
        // so the caller fails over rather than saving an HTML page as the book.
        $html = <<<'HTML'
            <html><body>
              <a href="https://annas-archive.gl/account">Account</a>
              <a href="/datasets">Datasets</a>
              <a href="https://annas-archive.se/faq">FAQ</a>
            </body></html>
            HTML;

        self::assertNull(
            $this->protocol->parsePartnerDownloadUrl($html, 'https://annas-archive.gl/slow_download/abc/0/1'),
        );
    }

    public function testParsePartnerDownloadUrlEmptyHtml(): void
    {
        self::assertNull($this->protocol->parsePartnerDownloadUrl('', 'https://annas-archive.gl/slow_download/abc/0/1'));
    }

    public function testParsePartnerDownloadUrlIgnoresFooterWikipediaLink(): void
    {
        // A waitlist-countdown page whose only off-mirror link is the footer
        // "Anna's Archive" Wikipedia link must NOT be mistaken for the download
        // (regression: it was, then failed as an "unresolved interstitial").
        $html = <<<'HTML'
            <html><body>
              <p>Please wait, your download will be ready soon…</p>
              <footer>
                <a href="https://en.wikipedia.org/wiki/Anna%27s_Archive">About Anna’s Archive</a>
                <a href="https://annas-archive.gl/donate">Donate</a>
              </footer>
            </body></html>
            HTML;

        self::assertNull(
            $this->protocol->parsePartnerDownloadUrl($html, 'https://annas-archive.gl/slow_download/d41d8cd98f00b204e9800998ecf8427e/0/2'),
        );
    }

    public function testParsePartnerDownloadUrlPrefersHashLinkOverChrome(): void
    {
        // The real partner link embeds the record's md5; a footer link must lose
        // to it regardless of document order.
        $md5 = 'd41d8cd98f00b204e9800998ecf8427e';
        $html = <<<HTML
            <html><body>
              <a href="https://en.wikipedia.org/wiki/Anna%27s_Archive">About</a>
              <a href="http://91.234.0.5:8080/d3/{$md5}/maus.epub">📚 Download now</a>
            </body></html>
            HTML;

        $url = $this->protocol->parsePartnerDownloadUrl($html, "https://annas-archive.gl/slow_download/{$md5}/0/2");

        self::assertSame("http://91.234.0.5:8080/d3/{$md5}/maus.epub", $url);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<string> $isbns
     * @param list<string> $languages
     */
    private function plan(array $isbns, string $title, string $author, array $languages = []): ReleaseSearchPlan
    {
        return new ReleaseSearchPlan(
            book: new Book('test', 'ext-1', $title),
            isbnCandidates: $isbns,
            author: $author,
            titleVariants: [$title],
            languages: $languages,
        );
    }

    private function fixture(string $name): string
    {
        $path = \dirname(__DIR__, 3) . '/Fixtures/responses/' . $name;
        $html = file_get_contents($path);
        self::assertIsString($html, "Missing fixture: {$name}");

        return $html;
    }
}
