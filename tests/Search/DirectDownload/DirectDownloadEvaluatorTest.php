<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadEvaluator;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\Match\MatchScorer;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttp\DirectHttpSource;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DirectDownloadEvaluatorTest extends TestCase
{
    private const AA = 'annas_archive';

    public function testEvaluateScoresThresholdsAndPicksBestMatch(): void
    {
        $evaluator = $this->evaluator(new BestMatchPolicy(minMatchScore: 50));
        $result = $evaluator->evaluate($this->plan('The Left Hand of Darkness', 'Ursula K. Le Guin', '9780441478125'));

        self::assertNull($result->unavailableReason);
        self::assertSame(50, $result->threshold);
        self::assertSame('https://m.test', $result->firstMirror());
        self::assertSame('annas_archive', $result->pickedSource);
        self::assertSame(2, $result->totalCount());

        // Both fixture rows are the same edition (matching ISBN/title/author) →
        // both reach 100 and qualify.
        self::assertSame(2, $result->qualifyingCount());
        foreach ($result->scored as $scored) {
            self::assertSame(100, $scored->score->total);
            self::assertTrue($scored->score->isbnMatched);
            self::assertContains('9780441478125', $scored->candidate->isbns);
        }

        // Default format priority puts EPUB ahead of the PDF edition.
        self::assertNotNull($result->pick);
        self::assertSame('epub', $result->pick->format);
    }

    public function testEvaluateLeavesNothingQualifyingForAnUnrelatedRequest(): void
    {
        $evaluator = $this->evaluator(new BestMatchPolicy(minMatchScore: 50));
        // Different book entirely: no ISBN match, no title/author overlap.
        $result = $evaluator->evaluate($this->plan('A Totally Different Book', 'Someone Else', '9990000000007'));

        self::assertSame(2, $result->totalCount());
        self::assertSame(0, $result->qualifyingCount());
        self::assertNull($result->pick);
    }

    public function testEvaluateReturnsUnavailableWhenSourceNotConfigured(): void
    {
        $evaluator = $this->evaluator(new BestMatchPolicy(), enabled: false);
        $result = $evaluator->evaluate($this->plan('Whatever', 'Nobody', '9780441478125'));

        self::assertNotNull($result->unavailableReason);
        self::assertSame([], $result->scored);
        self::assertNull($result->pick);
    }

    public function testFailsOverToTheNextSourceWhenTheFirstHasNoQualifyingMatch(): void
    {
        $title = 'Red Rising';
        $author = 'Pierce Brown';
        $isbn = '9781444758979';

        // libgen (priority 0) returns an unrelated, non-qualifying candidate;
        // zlibrary (priority 1) returns the matching edition.
        $libgen = new FakeReleaseSource('libgen', [
            new ReleaseCandidate(source: 'libgen', sourceId: 'lg1', title: 'Something Else', author: 'Nobody', format: 'pdf', extra: ['mirror' => 'https://lg.test']),
        ], []);
        $zlib = new FakeReleaseSource('zlibrary', [
            new ReleaseCandidate(source: 'zlibrary', sourceId: 'z1', title: $title, author: $author, format: 'epub', infoUrl: 'https://z.test/book/1', extra: ['mirror' => 'https://z.test']),
        ], [$isbn]);

        $evaluator = $this->evaluatorWith([$libgen, $zlib], ['libgen', 'zlibrary'], new BestMatchPolicy(minMatchScore: 50));
        $result = $evaluator->evaluate($this->plan($title, $author, $isbn));

        self::assertSame('zlibrary', $result->pickedSource);
        self::assertNotNull($result->pick);
        self::assertSame('z1', $result->pick->sourceId);
        // Both sources were considered, in priority order.
        self::assertSame(['libgen', 'zlibrary'], array_map(static fn ($s) => $s->sourceId, $result->searches));
        self::assertSame(1, $libgen->searchCalls);
        self::assertSame(1, $zlib->searchCalls);
    }

    public function testStopsAtTheFirstQualifyingSource(): void
    {
        $title = 'Red Rising';
        $author = 'Pierce Brown';
        $isbn = '9781444758979';

        $libgen = new FakeReleaseSource('libgen', [
            new ReleaseCandidate(source: 'libgen', sourceId: 'lg1', title: $title, author: $author, format: 'epub', extra: ['mirror' => 'https://lg.test']),
        ], [$isbn]);
        $zlib = new FakeReleaseSource('zlibrary', [
            new ReleaseCandidate(source: 'zlibrary', sourceId: 'z1', title: $title, author: $author, format: 'epub', extra: ['mirror' => 'https://z.test']),
        ], [$isbn]);

        $evaluator = $this->evaluatorWith([$libgen, $zlib], ['libgen', 'zlibrary'], new BestMatchPolicy(minMatchScore: 50));
        $result = $evaluator->evaluate($this->plan($title, $author, $isbn));

        self::assertSame('libgen', $result->pickedSource);
        self::assertSame('lg1', $result->pick?->sourceId);
        // The cascade stopped at libgen — zlibrary was never searched.
        self::assertSame(1, $libgen->searchCalls);
        self::assertSame(0, $zlib->searchCalls);
    }

    public function testDisabledSourceIsSkippedInTheCascade(): void
    {
        $title = 'Red Rising';
        $author = 'Pierce Brown';
        $isbn = '9781444758979';

        $libgen = new FakeReleaseSource('libgen', [
            new ReleaseCandidate(source: 'libgen', sourceId: 'lg1', title: $title, author: $author, format: 'epub', extra: ['mirror' => 'https://lg.test']),
        ], [$isbn]);

        // libgen present but DISABLED → skipped; only zlibrary runs.
        $zlib = new FakeReleaseSource('zlibrary', [
            new ReleaseCandidate(source: 'zlibrary', sourceId: 'z1', title: $title, author: $author, format: 'epub', extra: ['mirror' => 'https://z.test']),
        ], [$isbn]);

        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [
                ['id' => 'libgen', 'enabled' => false],
                ['id' => 'zlibrary', 'enabled' => true],
            ],
            'mirrors' => ['libgen' => ['https://lg.test'], 'zlibrary' => ['https://z.test']],
        ], new MirrorListNormalizer());

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn(new BestMatchPolicy(minMatchScore: 50));

        $result = (new DirectDownloadEvaluator([$libgen, $zlib], new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings))
            ->evaluate($this->plan($title, $author, $isbn));

        self::assertSame(0, $libgen->searchCalls);
        self::assertSame('zlibrary', $result->pickedSource);
        self::assertSame(['zlibrary'], array_map(static fn ($s) => $s->sourceId, $result->searches));
    }

    /**
     * @param list<\App\Search\Source\ReleaseSourceInterface> $sources
     * @param list<string>                                    $priorityIds
     */
    private function evaluatorWith(array $sources, array $priorityIds, BestMatchPolicy $policy): DirectDownloadEvaluator
    {
        $priority = array_map(static fn (string $id): array => ['id' => $id, 'enabled' => true], $priorityIds);
        $mirrors = [];
        foreach ($priorityIds as $id) {
            $mirrors[$id] = ['https://' . $id . '.test'];
        }
        $config = DirectDownloadConfig::fromArray(['indexerPriority' => $priority, 'mirrors' => $mirrors], new MirrorListNormalizer());

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn($policy);

        return new DirectDownloadEvaluator($sources, new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings);
    }

    private function evaluator(BestMatchPolicy $policy, bool $enabled = true): DirectDownloadEvaluator
    {
        $config = DirectDownloadConfig::fromArray(
            [
                'indexerPriority' => [['id' => self::AA, 'enabled' => $enabled]],
                'mirrors'         => [self::AA => ['https://m.test']],
            ],
            new MirrorListNormalizer(),
        );

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn($policy);

        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/search?')) {
                return new MockResponse($this->fixture('aa_search_results.html'));
            }

            return new MockResponse($this->fixture('aa_record_detail.html'));
        });

        $source = new DirectHttpSource($settings, new AAStyleHttpProtocol(), $client);

        return new DirectDownloadEvaluator([$source], new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings);
    }

    private function plan(string $title, string $author, string $isbn): ReleaseSearchPlan
    {
        $book = new Book('test', 'ext', $title);
        $book->setAuthor($author);
        $book->setIsbn($isbn);

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: [$isbn],
            author: $author,
            titleVariants: [$title],
        );
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/responses/' . $name);
        self::assertIsString($html);

        return $html;
    }
}

/**
 * In-memory release source for cascade tests: returns fixed candidates and a
 * fixed ISBN set on resolveDetail, and counts how many times it was searched so
 * tests can assert the failover stopped (or didn't) at the right source.
 */
final class FakeReleaseSource implements ReleaseSourceInterface
{
    public int $searchCalls = 0;

    /** @param list<ReleaseCandidate> $candidates @param list<string> $isbns */
    public function __construct(
        private readonly string $id,
        private readonly array $candidates,
        private readonly array $isbns,
    ) {
    }

    public function getName(): string
    {
        return $this->id;
    }

    public function sourceId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return ucfirst($this->id);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnavailableReason(): ?string
    {
        return null;
    }

    public function search(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        ++$this->searchCalls;

        return $this->candidates;
    }

    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        ++$this->searchCalls;

        return $this->candidates;
    }

    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string
    {
        return $mirror . '/q';
    }

    public function searchPlanUrl(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        return ['mirror' => 'https://' . $this->id . '.test', 'url' => 'https://' . $this->id . '.test/q'];
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        return ['isbns' => $this->isbns, 'raw' => [], 'links' => ['https://' . $this->id . '.test/dl'], 'error' => null];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        return [$mirror . '/dl/' . $item->sourceId];
    }
}
