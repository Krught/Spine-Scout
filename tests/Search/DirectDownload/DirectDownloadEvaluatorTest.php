<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadEvaluator;
use App\Search\Match\MatchScorer;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttp\DirectHttpSource;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseSearchPlan;
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
        self::assertSame('https://m.test', $result->mirror);
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

        return new DirectDownloadEvaluator($source, new MatchScorer(), new BestMatchSelector(), $settings);
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
