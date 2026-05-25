<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Triggers a refresh of Hardcover's trending books cache. `$force = true`
 * bypasses the per-integration refresh interval (used by the manual button).
 */
final readonly class RefreshHardcoverTrending
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
