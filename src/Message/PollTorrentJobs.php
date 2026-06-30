<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Periodic sweep that advances every in-flight torrent DownloadJob: query
 * qBittorrent for each, update progress, and — once a torrent finishes — run the
 * sanity checks and move its audio files into the library. Runs on a short
 * schedule because it is the only thing that finalizes an async torrent download.
 */
final readonly class PollTorrentJobs
{
}
