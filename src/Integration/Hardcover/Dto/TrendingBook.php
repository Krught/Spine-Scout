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
     * @param int|null     $usersCount Hardcover's book-level users_count (reader-count
     *                                 popularity proxy); null when the provider or query
     *                                 doesn't expose it
     */
    public function __construct(
        public string $title,
        public ?string $author = null,
        public ?string $coverUrl = null,
        public ?string $externalUrl = null,
        public array $isbns = [],
        public bool $audiobook = false,
        public ?int $usersCount = null,
    ) {
    }

    /** @return array{title: string, author: ?string, coverUrl: ?string, externalUrl: ?string, isbns: list<string>, audiobook: bool, usersCount: ?int} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'author' => $this->author,
            'coverUrl' => $this->coverUrl,
            'externalUrl' => $this->externalUrl,
            'isbns' => $this->isbns,
            'audiobook' => $this->audiobook,
            'usersCount' => $this->usersCount,
        ];
    }
}
