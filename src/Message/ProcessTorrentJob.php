<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Resolves one audiobook DownloadJob to a torrent and submits it to qBittorrent:
 * search Prowlarr, rank the results, add the best magnet, and stamp the job with
 * the torrent hash. Unlike the HTTP path this does NOT wait for completion — the
 * torrent downloads asynchronously and PollTorrentJobs finalizes it. Carries only
 * the job id; the job is reloaded and claimed under a row lock in the handler.
 */
final readonly class ProcessTorrentJob
{
    public function __construct(
        public int $downloadJobId,
    ) {
    }
}
