<?php

declare(strict_types=1);

namespace App\Integration\Grimmory\Dto;

/**
 * Projection of a Komga book record for the library listing. Heavier fields
 * (cover, pages, summary) belong to a later book-detail pass.
 *
 * `$isbn` is normalized from Komga's `metadata.isbn` (digits only,
 * uppercase 'X' allowed for ISBN-10) and is the canonical "have" key.
 */
final readonly class BookSummary
{
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $author,
        public ?string $series,
        public ?string $seriesIndex,
        public ?string $externalUrl,
        public ?string $libraryId,
        public ?string $isbn,
        public ?\DateTimeImmutable $addedAt,
        public ?\DateTimeImmutable $lastModifiedAt,
    ) {
    }
}
