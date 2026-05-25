<?php

declare(strict_types=1);

namespace App\Integration\Hardcover\Dto;

/**
 * Normalized projection of a trending entry from a metadata provider
 * (Hardcover or Open Library), shaped for the home page carousel.
 *
 * `$isbns` holds every ISBN-10 / ISBN-13 the provider exposes for this work,
 * normalized (digits only, trailing 'X' allowed). The home page uses this as
 * the canonical key to flag entries already in the library.
 */
final readonly class TrendingBook
{
    /**
     * @param list<string> $isbns
     */
    public function __construct(
        public string $title,
        public ?string $author = null,
        public ?string $coverUrl = null,
        public ?string $externalUrl = null,
        public array $isbns = [],
    ) {
    }

    /** @return array{title: string, author: ?string, coverUrl: ?string, externalUrl: ?string, isbns: list<string>} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'author' => $this->author,
            'coverUrl' => $this->coverUrl,
            'externalUrl' => $this->externalUrl,
            'isbns' => $this->isbns,
        ];
    }
}
