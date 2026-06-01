<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\LibGen;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\LibGen\LibGenSource;
use App\Search\Source\LibGenProtocol\LibGenHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LibGenSourceTest extends TestCase
{
    private const ID = 'libgen';

    public function testIsUnavailableWhenDisabledOrNoMirrors(): void
    {
        $disabled = $this->source($this->config(false, ['https://lg.test']), new MockHttpClient());
        self::assertFalse($disabled->isAvailable());

        $noMirrors = $this->source($this->config(true, []), new MockHttpClient());
        self::assertFalse($noMirrors->isAvailable());
        self::assertStringContainsString('mirror', (string) $noMirrors->getUnavailableReason());
    }

    public function testSearchMapsRowsToCandidates(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('libgen_search_results.html')));
        $candidates = $this->source($this->config(true, ['https://lg.test']), $client)->search($this->plan());

        self::assertCount(2, $candidates);
        self::assertSame(self::ID, $candidates[0]->source);
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $candidates[0]->sourceId);
        self::assertSame('https://lg.test', $candidates[0]->extra['mirror']);
        self::assertSame('https://lg.test/book.php?md5=aaaa1111bbbb2222cccc3333dddd4444', $candidates[0]->infoUrl);
        self::assertSame('epub', $candidates[0]->format);
    }

    public function testSearchCascadesPastAChallengeToTheNextMirror(): void
    {
        $client = new MockHttpClient(function (string $m, string $url): MockResponse {
            if (str_contains($url, 'mirror1.test')) {
                return new MockResponse('<html><body>Just a moment…</body></html>');
            }

            return new MockResponse($this->fixture('libgen_search_results.html'));
        });

        $candidates = $this->source($this->config(true, ['https://mirror1.test', 'https://mirror2.test']), $client)->search($this->plan());
        self::assertCount(2, $candidates);
        self::assertSame('https://mirror2.test', $candidates[0]->extra['mirror']);
    }

    public function testResolveDetailReturnsDownloadLinkAndIsbns(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('libgen_book_page.html')));
        $source = $this->source($this->config(true, ['https://lg.test']), $client);

        $candidate = new ReleaseCandidate(source: self::ID, sourceId: 'aaaa1111bbbb2222cccc3333dddd4444', title: 'X', extra: ['mirror' => 'https://lg.test']);
        $detail = $source->resolveDetail($candidate);

        self::assertNull($detail['error']);
        // The direct, streamable download endpoint is the link.
        self::assertContains('https://lg.test/download.php?md5=aaaa1111bbbb2222cccc3333dddd4444', $detail['links']);
        self::assertContains('9780441478125', $detail['isbns']);
    }

    public function testResolveDetailWithoutMirrorReturnsError(): void
    {
        $source = $this->source($this->config(true, ['https://lg.test']), new MockHttpClient());
        $detail = $source->resolveDetail(new ReleaseCandidate(source: self::ID, sourceId: 'h', title: 'X'));

        self::assertNotNull($detail['error']);
        self::assertSame([], $detail['links']);
    }

    /** @param list<string> $mirrors */
    private function config(bool $enabled, array $mirrors): DirectDownloadConfig
    {
        return DirectDownloadConfig::fromArray([
            'indexerPriority' => [['id' => self::ID, 'enabled' => $enabled]],
            'mirrors'         => [self::ID => $mirrors],
        ], new MirrorListNormalizer());
    }

    private function source(DirectDownloadConfig $config, MockHttpClient $client): LibGenSource
    {
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);

        return new LibGenSource($settings, $client, new LibGenHttpProtocol());
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
