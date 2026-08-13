<?php

declare(strict_types=1);

namespace App\Search\Torrent;

/**
 * The criteria TorrentMatchScorer applies to rank Prowlarr audiobook releases:
 * a hard seed floor / size cap, then a weighted blend of title-author match,
 * seeder health, size, and format preference. Derived from ProwlarrConfig.
 *
 * Immutable.
 */
final class TorrentMatchPolicy
{
    /**
     * Preferred audio container ranking (best → good). A single-file m4b/m4a is the
     * tidiest audiobook; loose mp3 sets are fine; anything unrecognised scores low.
     *
     * @var array<string, float>
     */
    public const FORMAT_RANK = [
        'm4b'  => 1.0,
        'm4a'  => 0.95,
        'mp3'  => 0.8,
        'flac' => 0.7,
        'ogg'  => 0.6,
        'opus' => 0.6,
        'aac'  => 0.6,
    ];

    /**
     * Preferred ebook format ranking (best → good), used when the policy scores an
     * ebook search instead of an audiobook one (the MAM source ranks both kinds).
     * Anything unlisted falls back to the same low default as the audio rank.
     *
     * @var array<string, float>
     */
    public const EBOOK_FORMAT_RANK = [
        'epub' => 1.0,
        'azw3' => 0.9,
        'mobi' => 0.8,
        'pdf'  => 0.5,
    ];

    /**
     * @param int                                                          $minSeeders   Releases below this are discarded
     * @param int|null                                                     $maxSizeBytes Releases above this are discarded; null = no cap
     * @param array{match: float, seeders: float, size: float, format: float} $weights    Relative scoring weights
     * @param array<string, float>                                         $formatRank   Format-preference table for formatScore();
     *                                                                                   defaults to the audio rank so existing
     *                                                                                   (Prowlarr) call sites are unchanged
     */
    public function __construct(
        public readonly int $minSeeders,
        public readonly ?int $maxSizeBytes,
        public readonly array $weights,
        public readonly array $formatRank = self::FORMAT_RANK,
    ) {
    }

    public static function fromProwlarrConfig(ProwlarrConfig $config): self
    {
        return new self($config->minSeeders, $config->maxSizeBytes, $config->weights);
    }

    /** Preference score (0..1) for a release's format string. Unknown/missing → low. */
    public function formatScore(?string $format): float
    {
        if ($format === null) {
            return 0.2;
        }

        return $this->formatRank[strtolower($format)] ?? 0.2;
    }
}
