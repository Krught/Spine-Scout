<?php

declare(strict_types=1);

namespace App\Tests\Search\Torrent;

use App\Search\Torrent\ProwlarrConfig;
use App\Search\Torrent\TorrentMatchPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The format-preference axis: the injectable rank table added for the MAM ebook
 * source, and the default audio behaviour existing Prowlarr call sites rely on
 * staying bit-identical.
 */
final class TorrentMatchPolicyTest extends TestCase
{
    public function testDefaultConstructionScoresTheAudioRank(): void
    {
        $policy = new TorrentMatchPolicy(minSeeders: 0, maxSizeBytes: null, weights: ProwlarrConfig::DEFAULT_WEIGHTS);

        self::assertSame(1.0, $policy->formatScore('m4b'));
        self::assertSame(1.0, $policy->formatScore('M4B'));
        self::assertSame(0.8, $policy->formatScore('mp3'));
        self::assertSame(0.2, $policy->formatScore('epub'), 'unlisted formats keep the low fallback');
        self::assertSame(0.2, $policy->formatScore(null));
    }

    public function testFromProwlarrConfigKeepsTheAudioRank(): void
    {
        $policy = TorrentMatchPolicy::fromProwlarrConfig(ProwlarrConfig::default());

        self::assertSame(TorrentMatchPolicy::FORMAT_RANK, $policy->formatRank);
        self::assertSame(1.0, $policy->formatScore('m4b'));
    }

    public function testEbookRankPrefersEpubOverPdfAndFallsBackForUnknowns(): void
    {
        $policy = new TorrentMatchPolicy(
            minSeeders: 0,
            maxSizeBytes: null,
            weights: ProwlarrConfig::DEFAULT_WEIGHTS,
            formatRank: TorrentMatchPolicy::EBOOK_FORMAT_RANK,
        );

        self::assertGreaterThan($policy->formatScore('pdf'), $policy->formatScore('epub'));
        self::assertSame(1.0, $policy->formatScore('epub'));
        self::assertSame(0.9, $policy->formatScore('azw3'));
        self::assertSame(0.8, $policy->formatScore('mobi'));
        self::assertSame(0.5, $policy->formatScore('pdf'));
        self::assertSame(0.2, $policy->formatScore('m4b'), 'audio containers are unlisted in the ebook rank');
        self::assertSame(0.2, $policy->formatScore(null));
    }
}
