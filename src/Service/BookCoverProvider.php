<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;

/**
 * Narrow read seam for fetching a book's cover image in an embeddable raster form.
 * Implemented by CoverCache (the single implementation, so Symfony auto-aliases
 * this interface to it) — keeps the metadata injector decoupled from the cache and
 * stubbable in unit tests.
 */
interface BookCoverProvider
{
    /**
     * @return array{0: string, 1: string}|null [raw image bytes, mime type], or null when unavailable
     */
    public function originalCoverForBook(Book $book): ?array;
}
