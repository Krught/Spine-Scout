<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the torrent job reconciler (see TorrentJobReconciler) to run.
 *
 * Two firing modes:
 *  - $force = false (the Scheduler tick): run only if `now - lastSyncAt` on the
 *    qbittorrent Integration row has reached its configured reconcileIntervalHours.
 *  - $force = true (manual "Run reconcile now" button): run regardless.
 */
final readonly class ReconcileTorrents
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
