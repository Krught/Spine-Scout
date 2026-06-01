<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Entity\Book;
use App\Mirror\MirrorListNormalizer;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\DirectDownload\DirectDownloadCascade;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DownloadAttempt;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\Match\MatchScorer;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use App\Download\FulfillmentLog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DirectDownloadCascadeTest extends TestCase
{
    private const TITLE = 'Red Rising';
    private const AUTHOR = 'Pierce Brown';
    private const ISBN = '9781444758979';

    public function testYieldsSourceThenMirrorThenItem(): void
    {
        // One source, 2 mirrors, 2 qualifying items → 4 attempts, mirror-outer.
        $src = $this->source('libgen', [
            $this->match('a'),
            $this->match('b'),
        ]);
        $cascade = $this->cascade([$src], ['libgen' => ['https://m1', 'https://m2']], ['libgen']);

        $attempts = iterator_to_array($cascade->attempts($this->plan()), false);

        self::assertCount(4, $attempts);
        self::assertSame(
            [['https://m1', 'a'], ['https://m1', 'b'], ['https://m2', 'a'], ['https://m2', 'b']],
            array_map(static fn (DownloadAttempt $x) => [$x->mirror, $x->item->sourceId], $attempts),
        );
        // mirror1 == found mirror → reuse the scored detail links; mirror2 → linksVia.
        self::assertSame(['https://m1.found/dl/a'], $attempts[0]->links);
        self::assertSame(['https://m2/dl/a'], $attempts[2]->links);
    }

    public function testSkipsSourceWithNoQualifyingItems(): void
    {
        $libgen = $this->source('libgen', [$this->nonMatch('x')]); // scores below threshold
        $zlib = $this->source('zlibrary', [$this->match('z')]);
        $cascade = $this->cascade(
            [$libgen, $zlib],
            ['libgen' => ['https://lg'], 'zlibrary' => ['https://z']],
            ['libgen', 'zlibrary'],
            threshold: 50,
        );

        $attempts = iterator_to_array($cascade->attempts($this->plan()), false);

        self::assertSame(['zlibrary'], array_values(array_unique(array_map(static fn (DownloadAttempt $x) => $x->sourceId, $attempts))));
        self::assertSame(1, $libgen->searchCalls, 'the skipped source is still searched (to score) but yields nothing');
    }

    public function testCapsAtThreeItemsPerSource(): void
    {
        $src = $this->source('libgen', array_map(fn (int $i) => $this->match("c$i"), range(1, 5)));
        $cascade = $this->cascade([$src], ['libgen' => ['https://m1']], ['libgen']);

        $attempts = iterator_to_array($cascade->attempts($this->plan()), false);

        self::assertCount(3, $attempts);
    }

    public function testLazyGeneratorStopsSearchingLaterSourcesOnceConsumerStops(): void
    {
        $libgen = $this->source('libgen', [$this->match('a')]);
        $zlib = $this->source('zlibrary', [$this->match('z')]);
        $cascade = $this->cascade(
            [$libgen, $zlib],
            ['libgen' => ['https://lg'], 'zlibrary' => ['https://z']],
            ['libgen', 'zlibrary'],
        );

        // Consume only the first attempt, then stop (as the download handler does on success).
        foreach ($cascade->attempts($this->plan()) as $attempt) {
            self::assertSame('libgen', $attempt->sourceId);
            break;
        }

        self::assertSame(1, $libgen->searchCalls);
        self::assertSame(0, $zlib->searchCalls, 'zlibrary must not be searched once the consumer stopped at libgen');
    }

    public function testLogsEachMirrorSearchedAndNoResults(): void
    {
        $lines = [];
        $src = $this->source('libgen', []); // searchVia returns [] for every mirror
        $cascade = $this->cascade([$src], ['libgen' => ['https://m1', 'https://m2']], ['libgen'], log: $this->capturingLog($lines));

        iterator_to_array($cascade->attempts($this->plan(), 'A Book'), false);

        self::assertContains('Searching LibGen [mirror 1/2]: https://m1/q', $lines);
        self::assertContains('Searching LibGen [mirror 2/2]: https://m2/q', $lines);
        self::assertContains('LibGen — no results from 2 mirror(s)', $lines);
    }

    public function testLogsSkippedUnavailableSourceInsteadOfSilentlyContinuing(): void
    {
        $lines = [];
        $welib = $this->source('welib', [$this->match('w')], 'Add at least one Welib mirror in Settings → Direct downloads.');
        $zlib = $this->source('zlibrary', [$this->match('z')]);
        $cascade = $this->cascade(
            [$welib, $zlib],
            ['welib' => ['https://w'], 'zlibrary' => ['https://z']],
            ['welib', 'zlibrary'],
            log: $this->capturingLog($lines),
        );

        $attempts = iterator_to_array($cascade->attempts($this->plan(), 'A Book'), false);

        // The unavailable source is reported (not silently skipped), and the cascade
        // still proceeds to the next source.
        self::assertContains('Welib — skipped: Add at least one Welib mirror in Settings → Direct downloads.', $lines);
        self::assertSame(0, $welib->searchCalls);
        self::assertSame(['zlibrary'], array_values(array_unique(array_map(static fn (DownloadAttempt $x) => $x->sourceId, $attempts))));
    }

    // --- helpers ----------------------------------------------------------

    private function source(string $id, array $candidates, ?string $unavailableReason = null): CascadeTestSource
    {
        return new CascadeTestSource($id, $candidates, $unavailableReason);
    }

    private function match(string $sourceId): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: 'src', sourceId: $sourceId, title: self::TITLE, format: 'epub',
            infoUrl: 'https://m1/book/' . $sourceId, author: self::AUTHOR, extra: ['mirror' => 'https://m1'],
        );
    }

    private function nonMatch(string $sourceId): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: 'src', sourceId: $sourceId, title: 'Totally Unrelated', format: 'epub',
            author: 'Nobody', extra: ['mirror' => 'https://lg'],
        );
    }

    /**
     * @param list<ReleaseSourceInterface>      $sources
     * @param array<string, list<string>>       $mirrors
     * @param list<string>                      $priorityIds
     */
    private function cascade(array $sources, array $mirrors, array $priorityIds, int $threshold = 0, ?FulfillmentLog $log = null): DirectDownloadCascade
    {
        $priority = array_map(static fn (string $id): array => ['id' => $id, 'enabled' => true], $priorityIds);
        $config = DirectDownloadConfig::fromArray(['indexerPriority' => $priority, 'mirrors' => $mirrors], new MirrorListNormalizer());

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn(new BestMatchPolicy(minMatchScore: $threshold));

        return new DirectDownloadCascade($sources, new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings, $log ?? $this->log());
    }

    /** A FulfillmentLog that records each message into $lines, for asserting log output. */
    private function capturingLog(array &$lines): FulfillmentLog
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$lines): int {
            $lines[] = (string) $data['message'];

            return 1;
        });

        return new FulfillmentLog($conn, new NullLogger());
    }

    private function log(): \App\Download\FulfillmentLog
    {
        return new \App\Download\FulfillmentLog($this->createStub(\Doctrine\DBAL\Connection::class), new \Psr\Log\NullLogger());
    }

    private function plan(): ReleaseSearchPlan
    {
        $book = new Book('t', 'e', self::TITLE);
        $book->setAuthor(self::AUTHOR);
        $book->setIsbn(self::ISBN);

        return new ReleaseSearchPlan(book: $book, isbnCandidates: [self::ISBN], author: self::AUTHOR, titleVariants: [self::TITLE]);
    }
}

/**
 * Fake source: returns fixed candidates and counts searches. resolveDetail
 * returns ISBN-matching detail (so matching candidates qualify) with a found-mirror
 * download link; linksVia returns a mirror-specific link so per-mirror reuse vs
 * re-resolution is observable in tests.
 */
final class CascadeTestSource implements ReleaseSourceInterface
{
    public int $searchCalls = 0;

    /** @param list<ReleaseCandidate> $candidates */
    public function __construct(
        private readonly string $id,
        private readonly array $candidates,
        private readonly ?string $unavailableReason = null,
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
        return $this->unavailableReason === null;
    }

    public function getUnavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    public function search(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        return $this->searchVia('https://m1', $plan, $config);
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
        return ['mirror' => 'https://m1', 'url' => 'https://m1/q'];
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        // Matching candidates (title 'Red Rising') carry the ISBN so they qualify.
        $isbns = $candidate->title === 'Red Rising' ? ['9781444758979'] : [];

        return ['isbns' => $isbns, 'raw' => [], 'links' => ['https://m1.found/dl/' . $candidate->sourceId], 'error' => null];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        return [$mirror . '/dl/' . $item->sourceId];
    }
}
