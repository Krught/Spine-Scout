<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\ZLibrary;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ZLibrary\ZLibrarySource;
use App\Search\Source\ZLibraryProtocol\ZLibraryHttpProtocol;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ZLibrarySourceTest extends TestCase
{
    private const ID = 'zlibrary';

    public function testIsUnavailableWhenDisabledOrNoMirrors(): void
    {
        $disabled = $this->source($this->config(false, ['https://z.test']), new MockHttpClient());
        self::assertFalse($disabled->isAvailable());

        $noMirrors = $this->source($this->config(true, []), new MockHttpClient());
        self::assertFalse($noMirrors->isAvailable());
    }

    public function testSearchMapsCardsToCandidates(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('zlib_search_results.html')));
        $candidates = $this->source($this->config(true, ['https://z.test']), $client)->search($this->plan());

        self::assertCount(2, $candidates);
        self::assertSame(self::ID, $candidates[0]->source);
        self::assertSame('1001/abcd12', $candidates[0]->sourceId);
        self::assertSame('https://z.test/book/1001/abcd12/the-left-hand-of-darkness.html', $candidates[0]->infoUrl);
        self::assertSame('https://z.test', $candidates[0]->extra['mirror']);
        self::assertSame('epub', $candidates[0]->format);
    }

    public function testResolveDetailFollowsBookPageForDownloadLink(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('zlib_book_page.html')));
        $source = $this->source($this->config(true, ['https://z.test']), $client);

        $candidate = new ReleaseCandidate(
            source: self::ID,
            sourceId: '1001/abcd12',
            title: 'X',
            infoUrl: 'https://z.test/book/1001/abcd12/x.html',
            extra: ['mirror' => 'https://z.test'],
        );
        $detail = $source->resolveDetail($candidate);

        self::assertNull($detail['error']);
        self::assertContains('https://z.test/dl/1001/abcd12?dsource=recommend', $detail['links']);
        self::assertContains('9780441478125', $detail['isbns']);
    }

    public function testResolveDetailWithoutBookPageReturnsError(): void
    {
        $source = $this->source($this->config(true, ['https://z.test']), new MockHttpClient());
        $detail = $source->resolveDetail(new ReleaseCandidate(source: self::ID, sourceId: '1', title: 'X', extra: ['mirror' => 'https://z.test']));

        self::assertNotNull($detail['error']);
    }

    /** @param list<string> $mirrors */
    private function config(bool $enabled, array $mirrors): DirectDownloadConfig
    {
        return DirectDownloadConfig::fromArray([
            'indexerPriority' => [['id' => self::ID, 'enabled' => $enabled]],
            'mirrors'         => [self::ID => $mirrors],
        ], new MirrorListNormalizer());
    }

    private function source(DirectDownloadConfig $config, MockHttpClient $client): ZLibrarySource
    {
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);

        return new ZLibrarySource($settings, $client, new ZLibraryHttpProtocol());
    }

    private function plan(): ReleaseSearchPlan
    {
        return new ReleaseSearchPlan(
            book: new Book('test', 'ext', 'The Left Hand of Darkness'),
            isbnCandidates: ['9780441478125'],
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
