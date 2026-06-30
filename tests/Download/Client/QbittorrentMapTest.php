<?php

declare(strict_types=1);

namespace App\Tests\Download\Client;

use App\Download\Client\DownloadStatus;
use App\Download\Client\QbittorrentDownloadClient;
use PHPUnit\Framework\TestCase;

final class QbittorrentMapTest extends TestCase
{
    public function testDownloadingMapsToDownloadingWithProgress(): void
    {
        $s = QbittorrentDownloadClient::mapTorrentRow([
            'state' => 'downloading', 'progress' => 0.42, 'content_path' => '/downloads/x',
            'dlspeed' => 1000, 'eta' => 300,
        ]);

        self::assertSame(DownloadStatus::STATE_DOWNLOADING, $s->state);
        self::assertSame(42.0, $s->progress);
        self::assertNull($s->filePath);
        self::assertSame(1000, $s->downloadSpeedBytesPerSec);
    }

    public function testSeedingMapsToSeedingWithContentPath(): void
    {
        $s = QbittorrentDownloadClient::mapTorrentRow([
            'state' => 'stalledUP', 'progress' => 1.0, 'content_path' => '/downloads/Book',
        ]);

        self::assertSame(DownloadStatus::STATE_SEEDING, $s->state);
        self::assertSame('/downloads/Book', $s->filePath);
    }

    public function testFullProgressIsSeedingEvenIfStateLags(): void
    {
        $s = QbittorrentDownloadClient::mapTorrentRow([
            'state' => 'checkingUP', 'progress' => 1.0, 'content_path' => '/downloads/Book',
        ]);

        self::assertSame(DownloadStatus::STATE_SEEDING, $s->state);
    }

    public function testErrorStateMapsToError(): void
    {
        $s = QbittorrentDownloadClient::mapTorrentRow(['state' => 'missingFiles', 'progress' => 0.5]);

        self::assertSame(DownloadStatus::STATE_ERROR, $s->state);
    }

    public function testPausedDownloadMapsToPaused(): void
    {
        $s = QbittorrentDownloadClient::mapTorrentRow(['state' => 'pausedDL', 'progress' => 0.3]);

        self::assertSame(DownloadStatus::STATE_PAUSED, $s->state);
    }
}
