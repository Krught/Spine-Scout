<?php

declare(strict_types=1);

namespace App\Search\Source;

use App\Entity\Book;

/**
 * Pre-computed search inputs shared across release sources for one book lookup.
 * Built once per approval (or per manual search) and passed to every source's search().
 *
 * Immutable.
 */
final class ReleaseSearchPlan
{
    /**
     * @param list<string> $isbnCandidates ISBNs to try, in preferred order
     * @param list<string> $languages      ISO codes, null = no language filter
     * @param list<string> $titleVariants  Title strings to try (e.g. localized variants)
     */
    public function __construct(
        public readonly Book $book,
        public readonly array $isbnCandidates,
        public readonly string $author,
        public readonly array $titleVariants,
        public readonly array $languages = [],
        public readonly string $contentType = ReleaseCandidate::CONTENT_EBOOK,
    ) {
    }

    public function hasIsbn(): bool
    {
        return $this->isbnCandidates !== [];
    }

    public function primaryTitle(): string
    {
        return $this->titleVariants[0] ?? '';
    }

    public function primaryQuery(): string
    {
        return trim($this->primaryTitle() . ' ' . $this->author);
    }
}
