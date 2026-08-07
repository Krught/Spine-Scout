<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Entity\DownloadJob;

/**
 * The completed-torrent import pipeline behind an interface, so the poller and the
 * reimport handler can depend on it without pulling in the mover/metadata
 * infrastructure (and so it can be stubbed in unit tests — the implementation is
 * final, same seam pattern as {@see TorrentFulfillmentInterface}).
 */
interface TorrentFinalizerInterface
{
    /**
     * Import a finished torrent into the library: resolve the client-reported
     * content path under the /downloads mount, run the audiobook or ebook branch
     * (whole-folder move, missing-tag fill, metadata injection, Grimmory sidecar),
     * then complete or fail the job — including the post-complete torrent cleanup
     * and the delayed Grimmory import trigger. Flushes the entity manager.
     */
    public function finalize(DownloadJob $job, DownloadStatus $status, string $subject, DownloadClientInterface $client): void;

    /**
     * Whether the torrent's raw files can still be re-imported: returns the
     * resolved local source path under /downloads when the torrent is still
     * present in the download client AND that path exists on disk. Null when the
     * job has no client ref, the client reports the torrent missing (or can't be
     * queried / errored), or the resolved path is gone.
     */
    public function sourceAvailability(DownloadJob $job, DownloadClientInterface $client): ?string;

    /**
     * Mark the job (and its request) errored with $message and flush. Public
     * because the poller errors jobs for conditions it detects itself (client
     * removal, client error) using the same bookkeeping the pipeline uses.
     */
    public function fail(DownloadJob $job, string $message): void;
}
