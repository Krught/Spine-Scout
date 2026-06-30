<?php

declare(strict_types=1);

namespace App\Download\Client;

use App\Download\Torrent\TorrentClientConfig;
use App\Entity\Integration;

/**
 * Narrow read seam over the operator's torrent download-client settings: the
 * `qbittorrent` Integration row (connection + credentials) and the move/destination
 * config. Implemented by IntegrationRepository (the single implementation, so Symfony
 * auto-aliases this interface to it).
 *
 * Exists so QbittorrentDownloadClient depends on just what it reads — and so that
 * path stays unit-testable without doubling the final repository.
 */
interface TorrentClientSettings
{
    /** The configured `qbittorrent` Integration row, or null when none is set. */
    public function qbittorrentIntegration(): ?Integration;

    public function getTorrentClientConfig(): TorrentClientConfig;
}
