<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\SearchSettingsProvider;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use PHPUnit\Framework\TestCase;

final class DirectDownloadProbeTest extends TestCase
{
    public function testSearchScoredViaSearchesTheChosenMirrorAndScores(): void
    {
        $source = new ProbeFakeSource('libgen', 'epub', ['https://lg.test/get/1']);
        $probe = $this->probe([$source], minMatchScore: 50);

        $plan = $probe->buildPlan('9780441478125', 'Pierce Brown', 'Red Rising');
        $scored = $probe->searchScoredVia('libgen', 'https://chosen.test', $plan, $this->config());

        self::assertCount(1, $scored);
        // The exact mirror the user picked was the one searched (no internal cascade).
        self::assertSame('https://chosen.test', $source->lastMirror);
        // The detail links ride along so Manual Download can use them without re-fetch.
        self::assertSame(['https://lg.test/get/1'], $scored[0]->detailLinks);
        // A real 0–100 match % is computed.
        self::assertGreaterThan(0, $scored[0]->score->total);
        self::assertLessThanOrEqual(100, $scored[0]->score->total);
    }

    public function testSearchScoredViaUnknownSourceReturnsEmpty(): void
    {
        $probe = $this->probe([new ProbeFakeSource('libgen', 'epub', [])], minMatchScore: 50);

        $plan = $probe->buildPlan('', 'Author', 'Title');
        self::assertSame([], $probe->searchScoredVia('zlibrary', 'https://m.test', $plan, $this->config()));
    }

    /**
     * @param list<ReleaseSourceInterface> $sources
     */
    private function probe(array $sources, int $minMatchScore): DirectDownloadProbe
    {
        $integrations = $this->createMock(SearchSettingsProvider::class);
        $integrations->method('getBestMatchPolicy')->willReturn(new BestMatchPolicy(minMatchScore: $minMatchScore));

        return new DirectDownloadProbe($integrations, $sources, new ReleaseSourceScorer(new MatchScorer()));
    }

    private function config(): DirectDownloadConfig
    {
        return DirectDownloadConfig::fromArray(['indexerPriority' => [], 'mirrors' => []], new \App\Mirror\MirrorListNormalizer());
    }
}

final class ProbeFakeSource implements ReleaseSourceInterface
{
    public ?string $lastMirror = null;

    /** @param list<string> $links */
    public function __construct(
        private readonly string $id,
        private readonly string $format,
        private readonly array $links,
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
        return $this->searchVia('https://m.test', $plan, $config);
    }

    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        $this->lastMirror = $mirror;

        return [new ReleaseCandidate(
            source: $this->id,
            sourceId: 'hash123',
            title: $plan->primaryTitle(),
            format: $this->format,
            protocol: ReleaseCandidate::PROTOCOL_HTTP,
            author: $plan->author,
            extra: ['mirror' => $mirror],
        )];
    }

    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string
    {
        return $mirror . '/q';
    }

    public function searchPlanUrl(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        return ['mirror' => 'https://m.test', 'url' => 'https://m.test/q'];
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        return ['isbns' => ['9780441478125'], 'raw' => [], 'links' => $this->links, 'error' => null];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array
    {
        return $this->links;
    }
}
