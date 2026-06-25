<?php

declare(strict_types=1);

namespace App\Tests\Search\BestMatch;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\Source\ReleaseCandidate;
use PHPUnit\Framework\TestCase;

final class BestMatchSelectorTest extends TestCase
{
    private BestMatchSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new BestMatchSelector();
    }

    public function testReturnsNullForEmptyCandidates(): void
    {
        self::assertNull($this->selector->pick([], BestMatchPolicy::default()));
    }

    public function testFormatPriorityActsAsHardGate(): void
    {
        $candidates = [
            $this->candidate('a', format: 'pdf'),
            $this->candidate('b', format: 'epub'),
        ];
        $policy = new BestMatchPolicy(formatPriority: ['epub']);
        $pick = $this->selector->pick($candidates, $policy);
        self::assertNotNull($pick);
        self::assertSame('b', $pick->sourceId);
    }

    public function testFormatPriorityRejectsFormatsNotInList(): void
    {
        $candidates = [
            $this->candidate('raw', format: 'raw'),
            $this->candidate('unknown', format: null),
        ];
        // Default policy lists epub/mobi/azw3/pdf/cbz/cbr — neither candidate qualifies.
        self::assertNull($this->selector->pick($candidates, BestMatchPolicy::default()));
    }

    public function testFormatPriorityPicksBetterFormatOverBetterTieBreaker(): void
    {
        $candidates = [
            // Bigger file but worse format.
            $this->candidate('big-pdf', format: 'pdf', sizeBytes: 50_000_000),
            // Smaller file but preferred format.
            $this->candidate('small-epub', format: 'epub', sizeBytes: 500_000),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub', 'pdf'],
            tieBreakers: [BestMatchPolicy::TIE_LARGEST_SIZE],
        );
        $pick = $this->selector->pick($candidates, $policy);
        self::assertSame('small-epub', $pick?->sourceId);
    }

    public function testTieBreakerLargestSizeWithinSameFormat(): void
    {
        $candidates = [
            $this->candidate('small', format: 'epub', sizeBytes: 500_000),
            $this->candidate('big',   format: 'epub', sizeBytes: 9_000_000),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub'],
            tieBreakers: [BestMatchPolicy::TIE_LARGEST_SIZE],
        );
        self::assertSame('big', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testTieBreakerMostDownloadedBeatsLargestSize(): void
    {
        $candidates = [
            $this->candidate('popular', format: 'epub', sizeBytes: 1_000_000, downloads: 5000),
            $this->candidate('huge',    format: 'epub', sizeBytes: 9_000_000, downloads: 12),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub'],
            tieBreakers: [
                BestMatchPolicy::TIE_MOST_DOWNLOADED,
                BestMatchPolicy::TIE_LARGEST_SIZE,
            ],
        );
        self::assertSame('popular', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testTieBreakerMostSeedersOnlyAppliesWithCounts(): void
    {
        $candidates = [
            $this->candidate('seeded', protocol: ReleaseCandidate::PROTOCOL_TORRENT, sizeBytes: 1_000_000, seeders: 80),
            $this->candidate('dead',   protocol: ReleaseCandidate::PROTOCOL_TORRENT, sizeBytes: 1_000_000, seeders: 1),
        ];
        $policy = new BestMatchPolicy(
            minSeeders: 1,
            tieBreakers: [BestMatchPolicy::TIE_MOST_SEEDERS],
        );
        self::assertSame('seeded', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testMinSeedersGatesTorrentsButNotHttp(): void
    {
        $candidates = [
            $this->candidate('dead-torrent', protocol: ReleaseCandidate::PROTOCOL_TORRENT, seeders: 0),
            $this->candidate('http-mirror',  protocol: ReleaseCandidate::PROTOCOL_HTTP),
        ];
        $policy = new BestMatchPolicy(minSeeders: 1);
        // Dead torrent filtered out → HTTP candidate wins.
        self::assertSame('http-mirror', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testMinSizeBytesGate(): void
    {
        $candidates = [
            $this->candidate('tiny',  sizeBytes: 1_000),
            $this->candidate('valid', sizeBytes: 5_000_000),
        ];
        $policy = new BestMatchPolicy(minSizeBytes: 50_000);
        self::assertSame('valid', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testMaxSizeBytesGate(): void
    {
        $candidates = [
            $this->candidate('tiny',     sizeBytes: 1_000_000),
            $this->candidate('massive',  sizeBytes: 5_000_000_000),
        ];
        $policy = new BestMatchPolicy(minSizeBytes: null, maxSizeBytes: 2_000_000_000, minSeeders: null);
        self::assertSame('tiny', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testRequireIsbnMatchKeepsOnlyMatchedCandidates(): void
    {
        $candidates = [
            $this->candidate('not-matched', format: 'epub'),
            $this->candidate('matched',     format: 'epub'),
        ];
        $policy = new BestMatchPolicy(requireIsbnMatch: true, formatPriority: ['epub'], tieBreakers: []);
        $pick = $this->selector->pick($candidates, $policy, isbnMatches: [0 => false, 1 => true]);
        self::assertSame('matched', $pick?->sourceId);
    }

    public function testRequireIsbnMatchReturnsNullWhenNoneMatch(): void
    {
        $candidates = [$this->candidate('a'), $this->candidate('b')];
        $policy = new BestMatchPolicy(requireIsbnMatch: true);
        self::assertNull($this->selector->pick($candidates, $policy));
    }

    public function testSourcePriorityBreaksTiesAfterFormat(): void
    {
        $candidates = [
            $this->candidate('a-from-second', source: 'source_b', format: 'epub'),
            $this->candidate('a-from-first',  source: 'source_a', format: 'epub'),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub'],
            sourcePriority: ['source_a', 'source_b'],
            tieBreakers: [],
        );
        self::assertSame('a-from-first', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testLanguagePriorityBeatsFormatPriority(): void
    {
        // Language is the first axis applied — an English PDF beats a German EPUB
        // when English is preferred even though EPUB is the preferred format.
        $candidates = [
            $this->candidate('en-pdf', format: 'pdf',  language: 'en'),
            $this->candidate('de-epub', format: 'epub', language: 'de'),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub', 'pdf'],
            languagePriority: ['en', 'de'],
            tieBreakers: [],
        );
        self::assertSame('en-pdf', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testEmptyFormatPriorityIsNoOp(): void
    {
        $candidates = [
            $this->candidate('first',  format: 'pdf',  sizeBytes: 2_000_000),
            $this->candidate('second', format: 'epub', sizeBytes: 5_000_000),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: [],
            tieBreakers: [BestMatchPolicy::TIE_LARGEST_SIZE],
        );
        self::assertSame('second', $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testPolicyRoundtripsThroughArray(): void
    {
        $original = new BestMatchPolicy(
            formatPriority: ['epub', 'pdf'],
            sourcePriority: ['source_a'],
            tieBreakers: [BestMatchPolicy::TIE_MOST_SEEDERS, BestMatchPolicy::TIE_LARGEST_SIZE],
            minSizeBytes: 10_000,
            maxSizeBytes: 500_000_000,
            minSeeders: 3,
            requireIsbnMatch: true,
            languagePriority: ['en', 'de'],
            minMatchScore: 65,
        );
        $reloaded = BestMatchPolicy::fromArray($original->toArray());
        self::assertEquals($original, $reloaded);
        self::assertSame(65, $reloaded->minMatchScore);
    }

    public function testMinMatchScoreDefaultsAndCoercesFromString(): void
    {
        // Default 50 (percent of achievable) — an ISBN-only match lands below it.
        self::assertSame(50, BestMatchPolicy::default()->minMatchScore);
        self::assertSame(85, BestMatchPolicy::fromArray(['minMatchScore' => '85'])->minMatchScore);
        // Blank/invalid falls back to the default rather than 0.
        self::assertSame(50, BestMatchPolicy::fromArray(['minMatchScore' => ''])->minMatchScore);
    }

    public function testFromArrayDropsUnknownTieBreakers(): void
    {
        $reloaded = BestMatchPolicy::fromArray([
            'tieBreakers' => ['not_a_real_tiebreaker', BestMatchPolicy::TIE_LARGEST_SIZE],
        ]);
        self::assertSame([BestMatchPolicy::TIE_LARGEST_SIZE], $reloaded->tieBreakers);
    }

    public function testRankReturnsFullOrderingAndPickIsItsHead(): void
    {
        $candidates = [
            $this->candidate('pdf-big',    format: 'pdf',  sizeBytes: 50_000_000),
            $this->candidate('epub-small', format: 'epub', sizeBytes: 500_000),
            $this->candidate('epub-big',   format: 'epub', sizeBytes: 9_000_000),
        ];
        $policy = new BestMatchPolicy(
            formatPriority: ['epub', 'pdf'],
            tieBreakers: [BestMatchPolicy::TIE_LARGEST_SIZE],
        );

        $ranked = $this->selector->rank($candidates, $policy);

        // epub bucket first (largest epub, then smaller epub), then pdf.
        self::assertSame(['epub-big', 'epub-small', 'pdf-big'], array_map(static fn ($c) => $c->sourceId, $ranked));
        // pick() is exactly rank()[0].
        self::assertSame($ranked[0]->sourceId, $this->selector->pick($candidates, $policy)?->sourceId);
    }

    public function testRankDropsGatedCandidates(): void
    {
        $candidates = [
            $this->candidate('pdf', format: 'pdf'),
            $this->candidate('epub', format: 'epub'),
        ];
        $ranked = $this->selector->rank($candidates, new BestMatchPolicy(formatPriority: ['epub']));

        self::assertSame(['epub'], array_map(static fn ($c) => $c->sourceId, $ranked));
    }

    private function candidate(
        string $sourceId,
        string $source = 'test',
        ?string $format = 'epub',
        ?string $language = null,
        ?int $sizeBytes = null,
        ?int $downloads = null,
        ?int $seeders = null,
        ?string $protocol = ReleaseCandidate::PROTOCOL_HTTP,
    ): ReleaseCandidate {
        return new ReleaseCandidate(
            source:     $source,
            sourceId:   $sourceId,
            title:      "Candidate {$sourceId}",
            format:     $format,
            language:   $language,
            sizeBytes:  $sizeBytes,
            protocol:   $protocol,
            seeders:    $seeders,
            downloads:  $downloads,
        );
    }
}
