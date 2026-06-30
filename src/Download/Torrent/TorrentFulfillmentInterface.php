<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Entity\DownloadJob;
use App\Search\Source\ReleaseSearchPlan;

/**
 * The torrent search-and-add step, behind an interface so the download handlers can
 * depend on it without pulling in the indexer/client infrastructure (and so it can
 * be stubbed in unit tests).
 */
interface TorrentFulfillmentInterface
{
    /** True when both an indexer manager and a download client are configured. */
    public function isAvailable(): bool;

    /**
     * Search indexers, add the best torrent to the download client, and stamp the
     * job (protocol=torrent, client_ref, DOWNLOADING). Returns false when nothing
     * matched; throws when the client rejects the add. Does not flush.
     *
     * @throws \RuntimeException on download-client failure
     */
    public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool;
}
