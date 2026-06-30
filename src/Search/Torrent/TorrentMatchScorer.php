<?php

declare(strict_types=1);

namespace App\Search\Torrent;

use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;

/**
 * Ranks Prowlarr audiobook torrents for one request. First a hard filter drops
 * anything below the seed floor or above the size cap; the survivors are then
 * scored on a weighted blend of four axes, each normalised to 0..1:
 *
 *   - match:   title/author/ISBN relevance (reuses the ebook MatchScorer)
 *   - seeders: health, normalised against the healthiest survivor
 *   - size:    larger is better (favours unabridged), normalised against the set
 *   - format:  container preference (m4b/m4a > mp3 > …)
 *
 * Match dominates by default so a healthy-but-wrong torrent never beats the
 * right book. Pure and deterministic — no I/O. Ties break on input order (stable).
 */
final class TorrentMatchScorer
{
    public function __construct(private readonly MatchScorer $matchScorer)
    {
    }

    /**
     * @param list<ReleaseCandidate> $candidates
     *
     * @return list<ReleaseCandidate> Qualifying candidates, best first
     */
    public function rank(array $candidates, ReleaseSearchPlan $plan, TorrentMatchPolicy $policy): array
    {
        return array_map(
            static fn (ScoredRelease $s): ReleaseCandidate => $s->candidate,
            $this->scored($candidates, $plan, $policy),
        );
    }

    /**
     * Same ranking as rank(), but returns the score breakdown alongside each
     * candidate — used by the dev inspector to show why one release won.
     *
     * @param list<ReleaseCandidate> $candidates
     *
     * @return list<ScoredRelease> Best first
     */
    public function scored(array $candidates, ReleaseSearchPlan $plan, TorrentMatchPolicy $policy): array
    {
        // Hard filter: seed floor + size cap.
        $qualified = [];
        foreach ($candidates as $c) {
            if (($c->seeders ?? 0) < $policy->minSeeders) {
                continue;
            }
            if ($policy->maxSizeBytes !== null && $c->sizeBytes !== null && $c->sizeBytes > $policy->maxSizeBytes) {
                continue;
            }
            $qualified[] = $c;
        }
        if ($qualified === []) {
            return [];
        }

        // Normalisation denominators across the surviving set.
        $maxSeeders = 0;
        $maxSize = 0;
        foreach ($qualified as $c) {
            $maxSeeders = max($maxSeeders, $c->seeders ?? 0);
            $maxSize = max($maxSize, $c->sizeBytes ?? 0);
        }

        $w = $policy->weights;
        $weightSum = ($w['match'] ?? 0) + ($w['seeders'] ?? 0) + ($w['size'] ?? 0) + ($w['format'] ?? 0);
        $weightSum = $weightSum > 0 ? $weightSum : 1.0;

        $scored = [];
        foreach ($qualified as $i => $c) {
            $matchScore = $this->matchScorer->score($c, $plan)->total / 100;
            $seedersScore = $maxSeeders > 0 ? ($c->seeders ?? 0) / $maxSeeders : 1.0;
            $sizeScore = $maxSize > 0 ? ($c->sizeBytes ?? 0) / $maxSize : 0.0;
            $formatScore = $policy->formatScore($c->format);

            $components = [
                'match'   => $matchScore,
                'seeders' => $seedersScore,
                'size'    => $sizeScore,
                'format'  => $formatScore,
            ];
            $total = (
                ($w['match'] ?? 0) * $matchScore
                + ($w['seeders'] ?? 0) * $seedersScore
                + ($w['size'] ?? 0) * $sizeScore
                + ($w['format'] ?? 0) * $formatScore
            ) / $weightSum;

            $scored[] = new ScoredRelease($c, $total, $components, $i);
        }

        // Sort by score desc; stable on the original index for ties.
        usort($scored, static function (ScoredRelease $a, ScoredRelease $b): int {
            return $b->score <=> $a->score ?: $a->order <=> $b->order;
        });

        return array_values($scored);
    }
}
