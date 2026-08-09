<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

/**
 * One torrent row from MyAnonamouse's JSON search endpoint, normalized: the
 * JSON-encoded author/narrator maps decoded to plain name lists, the human size
 * string parsed to bytes, the freeleech flags coerced to booleans. The title is
 * kept verbatim (MAM appends a "[ENG / EPUB]" bracket) — stripping it belongs to
 * the matching layer, not the transport.
 */
final readonly class MamRelease
{
    /**
     * @param string[] $authors
     * @param string[] $narrators
     */
    public function __construct(
        public int $mamTorrentId,
        public string $title,
        public array $authors,
        public array $narrators,
        public bool $audiobook,
        public ?string $catName,
        public ?string $langCode,
        public ?string $filetypes,
        public ?int $sizeBytes,
        public int $seeders,
        public int $leechers,
        public int $timesCompleted,
        public bool $vip,
        public bool $flVip,
        public bool $free,
        public bool $personalFreeleech,
        public ?string $dlHash,
        public ?string $thumbnailUrl,
        public ?\DateTimeImmutable $addedAt,
    ) {
    }
}
