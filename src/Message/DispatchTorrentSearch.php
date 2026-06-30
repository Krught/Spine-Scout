<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The audiobook counterpart of DispatchReleaseSearch: kicks off torrent
 * fulfillment for a just-approved audiobook request — search Prowlarr, score the
 * results, queue a DownloadJob, and hand off to ProcessTorrentJob. Carries only
 * the request id; the request is reloaded fresh in the handler.
 */
final readonly class DispatchTorrentSearch
{
    public function __construct(
        public int $bookRequestId,
    ) {
    }
}
