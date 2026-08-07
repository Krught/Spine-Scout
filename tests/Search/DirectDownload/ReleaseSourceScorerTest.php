<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Entity\Book;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\DirectDownload\ScoredCandidate;
use App\Search\Match\MatchScorer;
use App\Search\Source\BatchDetailResolverInterface;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The detail-resolution cap and the batch path. A search page can return 100+
 * rows and every detail page is its own request, so the scorer must resolve only
 * the candidates that could plausibly be shown or downloaded — and must ask a
 * batching source for all of them in one call.
 */
final class ReleaseSourceScorerTest extends TestCase
{
    private const ISBN = '9781444758979';

    public function testResolvesDetailOnlyForTheTopNCandidates(): void
    {
        $source = new ScorerFakeSource();
        $scored = $this->scorer()->scoreCandidates($source, $this->candidates(40), $this->plan(), 50, null, 5);

        self::assertSame(5, $source->detailCalls);
        self::assertCount(5, $scored);
    }

    public function testDefaultCapIsTwentyFive(): void
    {
        $source = new ScorerFakeSource();
        $scored = $this->scorer()->scoreCandidates($source, $this->candidates(40), $this->plan(), 50);

        self::assertSame(ReleaseSourceScorer::DEFAULT_DETAIL_LIMIT, $source->detailCalls);
        self::assertCount(25, $scored);
    }

    public function testNonPositiveLimitResolvesEverything(): void
    {
        $source = new ScorerFakeSource();
        $scored = $this->scorer()->scoreCandidates($source, $this->candidates(30), $this->plan(), 50, null, 0);

        self::assertSame(30, $source->detailCalls);
        self::assertCount(30, $scored);
    }

    /**
     * The preliminary pass scores off the search-page fields alone, so a strong
     * match buried at the end of a long results table still survives the cap.
     */
    public function testPreliminaryPassKeepsTheBestCandidatesNotTheFirstOnes(): void
    {
        $candidates = $this->candidates(20);
        $candidates[] = $this->candidate('gem', 'Red Rising', 'Pierce Brown');

        $source = new ScorerFakeSource();
        $scored = $this->scorer()->scoreCandidates($source, $candidates, $this->plan(), 50, null, 3);

        self::assertSame(3, $source->detailCalls);
        self::assertContains('gem', $source->resolvedIds);
        self::assertSame('gem', $scored[0]->candidate->sourceId);
    }

    /**
     * Final ordering comes from the re-score WITH detail data: an ISBN only the
     * detail page carries can lift a candidate above one that scored higher in the
     * preliminary pass.
     */
    public function testFinalOrderingUsesDetailIsbnsNotThePreliminaryScore(): void
    {
        $candidates = [
            $this->candidate('a', 'Red Rising', 'Pierce Brown'),
            $this->candidate('b', 'Red Rising', 'Pierce Brown'),
        ];
        $source = new ScorerFakeSource(isbnFor: 'b');

        $scored = $this->scorer()->scoreCandidates($source, $candidates, $this->plan(), 50, null, 10);

        self::assertSame('b', $scored[0]->candidate->sourceId);
        self::assertTrue($scored[0]->score->isbnMatched);
        self::assertSame([self::ISBN], $scored[0]->candidate->isbns);
        self::assertGreaterThan($scored[1]->score->total, $scored[0]->score->total);
    }

    public function testBatchingSourceIsAskedOnceAndResultsStayWithTheirCandidate(): void
    {
        $source = new ScorerBatchFakeSource();
        $candidates = [
            $this->candidate('a', 'Red Rising', 'Pierce Brown'),
            $this->candidate('b', 'Red Rising', 'Pierce Brown'),
            $this->candidate('c', 'Red Rising', 'Pierce Brown'),
        ];

        $scored = $this->scorer()->scoreCandidates($source, $candidates, $this->plan(), 50, null, 10);

        self::assertSame(1, $source->batchCalls, 'one batch call, not one call per candidate');
        self::assertSame(0, $source->singleCalls);
        self::assertCount(3, $scored);
        foreach ($scored as $entry) {
            self::assertSame(['https://dl/' . $entry->candidate->sourceId], $entry->detailLinks);
        }
    }

    public function testBatchOnlyResolvesTheCappedCandidates(): void
    {
        $source = new ScorerBatchFakeSource();
        $this->scorer()->scoreCandidates($source, $this->candidates(40), $this->plan(), 50, null, 4);

        self::assertSame(1, $source->batchCalls);
        self::assertCount(4, $source->resolvedIds);
    }

    /** One candidate's failure degrades that candidate only — the batch survives. */
    public function testBatchPerCandidateFailureDegradesOnlyThatCandidate(): void
    {
        $source = new ScorerBatchFakeSource(failFor: 'b');
        $candidates = [
            $this->candidate('a', 'Red Rising', 'Pierce Brown'),
            $this->candidate('b', 'Red Rising', 'Pierce Brown'),
            $this->candidate('c', 'Red Rising', 'Pierce Brown'),
        ];

        $byId = $this->byId($this->scorer()->scoreCandidates($source, $candidates, $this->plan(), 50, null, 10));

        self::assertSame('detail fetch failed', $byId['b']->detailError);
        self::assertSame([], $byId['b']->detailLinks);
        self::assertSame([], $byId['b']->candidate->isbns);
        self::assertNull($byId['a']->detailError);
        self::assertSame(['https://dl/a'], $byId['a']->detailLinks);
        self::assertNull($byId['c']->detailError);
    }

    /** A batch source that skips a key must not leave a candidate unresolved. */
    public function testIncompleteBatchFallsBackToSingleResolution(): void
    {
        $source = new ScorerBatchFakeSource(omit: 1);
        $candidates = [
            $this->candidate('a', 'Red Rising', 'Pierce Brown'),
            $this->candidate('b', 'Red Rising', 'Pierce Brown'),
        ];

        $byId = $this->byId($this->scorer()->scoreCandidates($source, $candidates, $this->plan(), 50, null, 10));

        self::assertSame(1, $source->singleCalls);
        self::assertSame(['https://dl/b'], $byId['b']->detailLinks);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<ScoredCandidate> $scored
     *
     * @return array<string, ScoredCandidate>
     */
    private function byId(array $scored): array
    {
        $out = [];
        foreach ($scored as $entry) {
            $out[$entry->candidate->sourceId] = $entry;
        }

        return $out;
    }

    private function scorer(): ReleaseSourceScorer
    {
        return new ReleaseSourceScorer(new MatchScorer());
    }

    /** @return list<ReleaseCandidate> */
    private function candidates(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[] = $this->candidate('filler-' . $i, 'Some Other Book ' . $i, 'Someone Else');
        }

        return $out;
    }

    private function candidate(string $id, string $title, string $author): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: 'fake',
            sourceId: $id,
            title: $title,
            author: $author,
            extra: ['mirror' => 'https://m1'],
        );
    }

    private function plan(): ReleaseSearchPlan
    {
        $book = new Book('t', 'e', 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $book->setIsbn(self::ISBN);

        return new ReleaseSearchPlan(book: $book, isbnCandidates: [self::ISBN], author: 'Pierce Brown', titleVariants: ['Red Rising']);
    }
}

/** Counts per-candidate detail resolutions so the cap is observable. */
class ScorerFakeSource implements ReleaseSourceInterface
{
    public int $detailCalls = 0;

    /** @var list<string> */
    public array $resolvedIds = [];

    public function __construct(private readonly ?string $isbnFor = null)
    {
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function sourceId(): string
    {
        return 'fake';
    }

    public function getDisplayName(): string
    {
        return 'Fake';
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
        return [];
    }

    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        return [];
    }

    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string
    {
        return $mirror . '/q';
    }

    public function searchPlanUrl(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        return ['mirror' => 'https://m1', 'url' => 'https://m1/q'];
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        ++$this->detailCalls;
        $this->resolvedIds[] = $candidate->sourceId;

        return [
            'isbns' => $this->isbnFor === $candidate->sourceId ? ['9781444758979'] : [],
            'raw'   => [],
            'links' => ['https://dl/' . $candidate->sourceId],
            'error' => null,
        ];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        return [$mirror . '/dl/' . $item->sourceId];
    }
}

/** Same, but resolves details in one batch call (the HTTP sources' path). */
final class ScorerBatchFakeSource extends ScorerFakeSource implements BatchDetailResolverInterface
{
    public int $batchCalls = 0;

    public int $singleCalls = 0;

    /** @var list<string> */
    public array $resolvedIds = [];

    public function __construct(
        private readonly ?string $failFor = null,
        private readonly ?int $omit = null,
    ) {
        parent::__construct();
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        ++$this->singleCalls;
        $this->resolvedIds[] = $candidate->sourceId;

        return ['isbns' => [], 'raw' => [], 'links' => ['https://dl/' . $candidate->sourceId], 'error' => null];
    }

    public function resolveDetails(array $candidates, ?DirectDownloadConfig $config = null): array
    {
        ++$this->batchCalls;

        $out = [];
        foreach ($candidates as $key => $candidate) {
            if ($key === $this->omit) {
                continue;
            }
            $this->resolvedIds[] = $candidate->sourceId;
            $out[$key] = $candidate->sourceId === $this->failFor
                ? ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'detail fetch failed']
                : ['isbns' => [], 'raw' => [], 'links' => ['https://dl/' . $candidate->sourceId], 'error' => null];
        }

        return $out;
    }
}
