<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\DirectHttp;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttp\DirectHttpSource;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DirectHttpSourceTest extends TestCase
{
    private const AA = 'annas_archive';

    public function testIsUnavailableWhenSourceDisabled(): void
    {
        $source = $this->source($this->config(enabled: false, mirrors: ['https://m.test']), new MockHttpClient());
        self::assertFalse($source->isAvailable());
        self::assertNotNull($source->getUnavailableReason());
    }

    public function testIsUnavailableWhenNoMirrors(): void
    {
        $source = $this->source($this->config(enabled: true, mirrors: []), new MockHttpClient());
        self::assertFalse($source->isAvailable());
        self::assertStringContainsString('mirror', (string) $source->getUnavailableReason());
    }

    public function testSearchCascadesPastAChallengePageToTheNextMirror(): void
    {
        $searchHtml = $this->fixture('aa_search_results.html');
        $requested = [];

        $client = new MockHttpClient(function (string $method, string $url) use ($searchHtml, &$requested): MockResponse {
            $requested[] = $url;
            if (str_contains($url, 'mirror1.test')) {
                // First mirror returns a challenge/landing page: no results table.
                return new MockResponse('<html><body>Just a moment…</body></html>');
            }

            return new MockResponse($searchHtml);
        });

        $source = $this->source($this->config(true, ['https://mirror1.test', 'https://mirror2.test']), $client);
        $candidates = $source->search($this->plan());

        self::assertCount(2, $candidates);
        $first = $candidates[0];
        self::assertInstanceOf(ReleaseCandidate::class, $first);
        self::assertSame(DirectHttpSource::NAME, $first->source);
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $first->sourceId);
        self::assertSame('The Left Hand of Darkness', $first->title);
        self::assertSame('Ursula K. Le Guin', $first->author);
        self::assertSame(ReleaseCandidate::PROTOCOL_HTTP, $first->protocol);
        // Mapped against the mirror that actually answered (the second one).
        self::assertSame('https://mirror2.test', $first->extra['mirror']);
        self::assertSame('https://mirror2.test/md5/aaaa1111bbbb2222cccc3333dddd4444', $first->infoUrl);
        // Both mirrors were tried, in order.
        self::assertStringContainsString('mirror1.test', $requested[0]);
        self::assertStringContainsString('mirror2.test', $requested[1]);
    }

    public function testSearchReturnsEmptyWhenAllMirrorsFail(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('No files found.'));
        $source = $this->source($this->config(true, ['https://mirror1.test']), $client);

        self::assertSame([], $source->search($this->plan()));
    }

    public function testFetchRecordDetailReturnsVerifiedIsbnsAndLinks(): void
    {
        $detailHtml = $this->fixture('aa_record_detail.html');
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($detailHtml));
        $source = $this->source($this->config(true, ['https://m.test']), $client);

        $detail = $source->fetchRecordDetail('https://m.test', 'aaaa1111bbbb2222cccc3333dddd4444');

        self::assertNull($detail['error']);
        self::assertSame(['9780441478125', '9780199536832', '0441478123'], $detail['isbns']);
        self::assertNotEmpty($detail['links']);
        self::assertContains('https://m.test/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/0', $detail['links']);
        // Fast-partner link excluded while the setting is off (default).
        self::assertNotContains('https://m.test/fast_download/aaaa1111bbbb2222cccc3333dddd4444/0/0', $detail['links']);
    }

    public function testFetchRecordDetailIncludesFastLinksWhenSettingEnabled(): void
    {
        $detailHtml = $this->fixture('aa_record_detail.html');
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($detailHtml));
        $source = $this->source($this->config(true, ['https://m.test'], fast: true), $client);

        $detail = $source->fetchRecordDetail('https://m.test', 'aaaa1111bbbb2222cccc3333dddd4444');

        self::assertContains('https://m.test/fast_download/aaaa1111bbbb2222cccc3333dddd4444/0/0', $detail['links']);
        self::assertContains('https://m.test/slow_download/aaaa1111bbbb2222cccc3333dddd4444/0/0', $detail['links']);
    }

    // --- helpers ----------------------------------------------------------

    /** @param list<string> $mirrors */
    private function config(bool $enabled, array $mirrors, bool $fast = false): DirectDownloadConfig
    {
        return DirectDownloadConfig::fromArray(
            [
                'indexerPriority'     => [['id' => self::AA, 'enabled' => $enabled]],
                'mirrors'             => [self::AA => $mirrors],
                'fastDownloadEnabled' => $fast,
            ],
            new MirrorListNormalizer(),
        );
    }

    private function source(DirectDownloadConfig $config, MockHttpClient $client): DirectHttpSource
    {
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);

        return new DirectHttpSource($settings, new AAStyleHttpProtocol(), $client);
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
