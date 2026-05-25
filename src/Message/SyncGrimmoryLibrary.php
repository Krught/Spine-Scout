<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the Grimmory library sync to run.
 *
 * Two firing modes:
 *  - $force = false (the Scheduler tick): run only if `now - lastSyncAt`
 *    has reached the integration's `syncIntervalMinutes`.
 *  - $force = true (manual "Sync now" button): run regardless.
 */
final readonly class SyncGrimmoryLibrary
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
