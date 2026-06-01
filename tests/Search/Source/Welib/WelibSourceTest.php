<?php

declare(strict_types=1);

namespace App\Tests\Search\Source\Welib;

use App\Download\Bypass\BypasserInterface;
use App\Download\Bypass\BypassResolver;
use App\Download\Progress\DownloadProgressReporter;
use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\Welib\WelibSearchParser;
use App\Search\Source\Welib\WelibSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Welib's /md5 record page is still Anna's-Archive-shaped (so resolveDetail is
 * exercised against the AA detail fixture), but its /search page now returns the
 * AA "book-card" div layout, parsed by WelibSearchParser against a Welib fixture.
 * Keyed on 'welib' throughout.
 */
final class WelibSourceTest extends TestCase
{
    private const ID = 'welib';

    public function testSourceIdAndNameAreWelib(): void
    {
        $source = $this->source($this->config(true, ['https://w.test']), new MockHttpClient());
        self::assertSame('welib', $source->sourceId());
        self::assertSame('welib', $source->getName());
        self::assertSame('Welib', $source->getDisplayName());
    }

    public function testSearchMapsCardLayoutToWelibCandidates(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('welib_search_results.html')));
        $candidates = $this->source($this->config(true, ['https://w.test']), $client)->search($this->plan());

        self::assertCount(2, $candidates);

        $first = $candidates[0];
        self::assertSame(self::ID, $first->source);
        self::assertSame('Welib', $first->indexer);
        self::assertSame('aaaa1111bbbb2222cccc3333dddd4444', $first->sourceId);
        self::assertSame('https://w.test/md5/aaaa1111bbbb2222cccc3333dddd4444', $first->infoUrl);
        self::assertSame('The Left Hand of Darkness', $first->title);
        // Trailing "; " that Welib appends to the author is stripped.
        self::assertSame('Ursula K. Le Guin', $first->author);
        // "epub · PDF" → the leading extension token, lowercased.
        self::assertSame('epub', $first->format);
        self::assertSame('English', $first->language);
        self::assertSame('1969', $first->year);
        self::assertSame((int) round(1.2 * 1024 ** 2), $first->sizeBytes);

        self::assertSame('pdf', $candidates[1]->format);
        self::assertSame('2014', $candidates[1]->year);
    }

    public function testResolveDetailReturnsIsbnsAndLinks(): void
    {
        $client = new MockHttpClient(fn (string $m, string $url): MockResponse => new MockResponse($this->fixture('aa_record_detail.html')));
        $source = $this->source($this->config(true, ['https://w.test']), $client);

        $candidate = new ReleaseCandidate(source: self::ID, sourceId: 'aaaa1111bbbb2222cccc3333dddd4444', title: 'X', extra: ['mirror' => 'https://w.test']);
        $detail = $source->resolveDetail($candidate);

        self::assertNull($detail['error']);
        self::assertContains('9780441478125', $detail['isbns']);
        self::assertNotEmpty($detail['links']);
    }

    public function testResolveDetailFetchesRecordPageThroughBypassWhenEnabled(): void
    {
        // The /md5 record page sits behind the same challenge as the download
        // endpoints, so with a bypass (FlareSolverr) enabled the page must be
        // resolved through it — a plain GET would only get the challenge body.
        $httpCalls = [];
        $client = new MockHttpClient(function (string $m, string $url) use (&$httpCalls): MockResponse {
            $httpCalls[] = $url;

            return new MockResponse('challenge — must not be used');
        });
        $bypasser = $this->bypasser(DirectDownloadConfig::BYPASS_EXTERNAL, $this->fixture('aa_record_detail.html'));
        $resolver = $this->resolver($bypasser, DirectDownloadConfig::BYPASS_EXTERNAL);
        $source = $this->source($this->config(true, ['https://w.test']), $client, $resolver);

        $candidate = new ReleaseCandidate(source: self::ID, sourceId: 'aaaa1111bbbb2222cccc3333dddd4444', title: 'X', extra: ['mirror' => 'https://w.test']);
        $detail = $source->resolveDetail($candidate);

        self::assertNull($detail['error']);
        self::assertContains('9780441478125', $detail['isbns']);
        self::assertSame([], $httpCalls, 'record page must be fetched via the bypasser, not a plain GET');
    }

    /** @param list<string> $mirrors */
    private function config(bool $enabled, array $mirrors): DirectDownloadConfig
    {
        return DirectDownloadConfig::fromArray([
            'indexerPriority' => [['id' => self::ID, 'enabled' => $enabled]],
            'mirrors'         => [self::ID => $mirrors],
        ], new MirrorListNormalizer());
    }

    private function source(DirectDownloadConfig $config, MockHttpClient $client, ?BypassResolver $bypass = null): WelibSource
    {
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);

        $protocol = new AAStyleHttpProtocol();

        return new WelibSource($settings, $client, $protocol, new WelibSearchParser($protocol), $bypass ?? $this->resolver());
    }

    /** A BypassResolver wired with an optional fake bypasser and a fixed mode (default: off). */
    private function resolver(?BypasserInterface $bypasser = null, string $mode = DirectDownloadConfig::BYPASS_NONE): BypassResolver
    {
        $config = new DirectDownloadConfig([], [], bypassMode: $mode);
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);

        return new BypassResolver($bypasser !== null ? [$bypasser] : [], $settings, new NullLogger());
    }

    /** A fake bypasser of the given mode that returns $html (null = couldn't resolve). */
    private function bypasser(string $mode, ?string $html): BypasserInterface
    {
        return new class($mode, $html) implements BypasserInterface {
            public function __construct(private string $modeValue, private ?string $html)
            {
            }

            public function mode(): string
            {
                return $this->modeValue;
            }

            public function isConfigured(DirectDownloadConfig $config): bool
            {
                return true;
            }

            public function fetch(string $url, DirectDownloadConfig $config, DownloadProgressReporter $progress): ?string
            {
                return $this->html;
            }
        };
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
