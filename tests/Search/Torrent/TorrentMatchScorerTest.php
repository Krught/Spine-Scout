<?php

declare(strict_types=1);

namespace App\Tests\Search\Torrent;

use App\Entity\Book;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use App\Search\Torrent\TorrentMatchPolicy;
use App\Search\Torrent\TorrentMatchScorer;
use PHPUnit\Framework\TestCase;

final class TorrentMatchScorerTest extends TestCase
{
    private TorrentMatchScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new TorrentMatchScorer(new MatchScorer());
    }

    public function testDropsReleasesBelowSeedFloor(): void
    {
        $plan = $this->plan('Dungeon Crawler Carl', 'Matt Dinniman');
        $policy = new TorrentMatchPolicy(minSeeders: 5, maxSizeBytes: null, weights: ProwlarrConfig::DEFAULT_WEIGHTS);

        $ranked = $this->scorer->rank([
            $this->candidate('Dungeon Crawler Carl M4B', 'Matt Dinniman', seeders: 2, size: 500_000_000),
            $this->candidate('Dungeon Crawler Carl MP3', 'Matt Dinniman', seeders: 50, size: 500_000_000),
        ], $plan, $policy);

        self::assertCount(1, $ranked);
        self::assertSame('Dungeon Crawler Carl MP3', $ranked[0]->title);
    }

    public function testDropsReleasesAboveSizeCap(): void
    {
        $plan = $this->plan('Dungeon Crawler Carl', 'Matt Dinniman');
        $policy = new TorrentMatchPolicy(minSeeders: 0, maxSizeBytes: 1_000_000_000, weights: ProwlarrConfig::DEFAULT_WEIGHTS);

        $ranked = $this->scorer->rank([
            $this->candidate('Dungeon Crawler Carl (huge pack)', 'Matt Dinniman', seeders: 10, size: 50_000_000_000),
        ], $plan, $policy);

        self::assertCount(0, $ranked);
    }

    public function testHealthierSeededReleaseWinsWhenMatchEqual(): void
    {
        $plan = $this->plan('Dungeon Crawler Carl', 'Matt Dinniman');
        // Seeders-only weighting so the match axis (equal here) doesn't dominate.
        $policy = new TorrentMatchPolicy(0, null, ['match' => 0.0, 'seeders' => 1.0, 'size' => 0.0, 'format' => 0.0]);

        $ranked = $this->scorer->rank([
            $this->candidate('Dungeon Crawler Carl', 'Matt Dinniman', seeders: 10, size: 500_000_000),
            $this->candidate('Dungeon Crawler Carl', 'Matt Dinniman', seeders: 99, size: 500_000_000),
        ], $plan, $policy);

        self::assertSame(99, $ranked[0]->seeders);
    }

    public function testStrongerTitleMatchBeatsHealthierWrongBook(): void
    {
        $plan = $this->plan('Dungeon Crawler Carl', 'Matt Dinniman');
        $policy = TorrentMatchPolicy::fromProwlarrConfig(ProwlarrConfig::default());

        $ranked = $this->scorer->rank([
            // Wrong book, very healthy.
            $this->candidate('Some Other Audiobook', 'Another Author', seeders: 999, size: 800_000_000),
            // Right book, fewer seeders.
            $this->candidate('Dungeon Crawler Carl', 'Matt Dinniman', seeders: 10, size: 500_000_000),
        ], $plan, $policy);

        self::assertSame('Dungeon Crawler Carl', $ranked[0]->title);
    }

    public function testEmptyInputYieldsEmptyRanking(): void
    {
        $plan = $this->plan('Anything', 'Anyone');
        self::assertSame([], $this->scorer->rank([], $plan, TorrentMatchPolicy::fromProwlarrConfig(ProwlarrConfig::default())));
    }

    private function plan(string $title, string $author): ReleaseSearchPlan
    {
        $book = new Book('test', 'ext-1', $title);
        $book->setAuthor($author);

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: [],
            author: $author,
            titleVariants: [$title],
            contentType: ReleaseCandidate::CONTENT_AUDIOBOOK,
        );
    }

    private function candidate(string $title, string $author, int $seeders, int $size): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: 'prowlarr',
            sourceId: 'guid-' . md5($title . $seeders . $size),
            title: $title,
            format: 'mp3',
            sizeBytes: $size,
            downloadUrl: 'magnet:?xt=urn:btih:' . md5($title),
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            seeders: $seeders,
            contentType: ReleaseCandidate::CONTENT_AUDIOBOOK,
            author: $author,
        );
    }
}
