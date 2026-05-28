<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Bumps `books.last_seen_at = NOW()` for every id in `$bookIds`. Dispatched as a single
 * message per page render (home/browse) so we get one batched UPDATE rather than per-row
 * writes; the purge job uses `last_seen_at` to retire stale metadata-only rows.
 */
final readonly class TouchBooksSeen
{
    /** @param list<int> $bookIds */
    public function __construct(public array $bookIds)
    {
    }
}
