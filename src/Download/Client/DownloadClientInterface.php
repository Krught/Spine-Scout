<?php

declare(strict_types=1);

namespace App\Download\Client;

/**
 * Plugin contract for a download client: moves bytes from a URL/magnet/NZB to disk.
 *
 * Implementations are autoconfigured with the `app.download_client` tag
 * (see config/services.yaml _instanceof block). The dispatcher picks the first
 * registered client whose getProtocol() matches the release's protocol and
 * whose isConfigured() returns true.
 *
 * This interface is the seam HttpDownloadClient (and any future
 * qBittorrent/Transmission/NZBGet adapter) hangs off of.
 */
interface DownloadClientInterface
{
    /**
     * Stable identifier (e.g. "http", "qbittorrent", "transmission").
     */
    public function getName(): string;

    /**
     * Which release protocol this client handles
     * (one of ReleaseCandidate::PROTOCOL_*).
     */
    public function getProtocol(): string;

    /**
     * True when required settings (URL, credentials, …) are present.
     */
    public function isConfigured(): bool;

    /**
     * Quick connectivity check. Returns [success, message].
     *
     * @return array{0: bool, 1: string}
     */
    public function testConnection(): array;

    /**
     * Submit a download. Returns a client-native ID used by getStatus / cancel.
     *
     * @param array<string, mixed> $options Per-client extras (category, label, etc.)
     */
    public function addDownload(string $url, string $name, array $options = []): string;

    public function getStatus(string $downloadId): DownloadStatus;

    public function cancel(string $downloadId, bool $deleteFiles = false): bool;
}
