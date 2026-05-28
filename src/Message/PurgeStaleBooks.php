<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Deletes metadata-only books (`downloaded = false`) whose `last_seen_at` is older than the
 * admin-configured purge threshold AND that are not currently referenced by a book request
 * or section entry. `$force` skips the integration-disabled short-circuit (manual button).
 */
final readonly class PurgeStaleBooks
{
    public function __construct(public bool $force = false)
    {
    }
}
