<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Triggers a refresh of Open Library's trending books cache. `$force = true`
 * bypasses the per-integration refresh interval (used by the manual button).
 */
final readonly class RefreshOpenLibraryTrending
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
