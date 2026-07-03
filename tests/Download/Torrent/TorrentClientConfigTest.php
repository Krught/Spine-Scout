<?php

declare(strict_types=1);

namespace App\Tests\Download\Torrent;

use App\Download\Torrent\TorrentClientConfig;
use PHPUnit\Framework\TestCase;

final class TorrentClientConfigTest extends TestCase
{
    public function testLocalContentPathResolvesByBasenameUnderDownloadsMount(): void
    {
        // The client's own absolute save path is irrelevant — only the basename, joined
        // to the fixed /downloads mount.
        self::assertSame(
            '/downloads/Red Rising (Unabridged) by Pierce Brown',
            TorrentClientConfig::localContentPath('/mnt/videos/torr/Red Rising (Unabridged) by Pierce Brown'),
        );
    }

    public function testLocalContentPathHandlesTrailingSlashAndSingleFile(): void
    {
        self::assertSame('/downloads/Book', TorrentClientConfig::localContentPath('/some/where/Book/'));
        self::assertSame('/downloads/book.m4b', TorrentClientConfig::localContentPath('/data/done/book.m4b'));
    }

    public function testLocalContentPathResolvesRelativeToSavePathWhenKnown(): void
    {
        // A single-file torrent whose content sits in an intermediate folder below
        // save_path — basename-only would drop that folder and miss the file.
        self::assertSame(
            '/downloads/Brave New World/Brave New World - Audiobook.mp3',
            TorrentClientConfig::localContentPath(
                '/mnt/videos/torr/Brave New World/Brave New World - Audiobook.mp3',
                '/mnt/videos/torr',
            ),
        );
    }

    public function testLocalContentPathFallsBackToBasenameWhenSavePathDoesNotMatch(): void
    {
        self::assertSame(
            '/downloads/Book',
            TorrentClientConfig::localContentPath('/mnt/videos/torr/Book', '/some/other/root'),
        );
    }

    public function testConfigRoundTripsWithoutPathFields(): void
    {
        $config = TorrentClientConfig::fromArray([
            'category'             => 'ab',
            'audioOutputDirectory' => '/audiobooks',
            'useEbookLibraryDir'   => true,
            'stagingSubdir'        => 'torrents',
            'filenameTemplate'     => '{Author} - {Title}',
        ]);

        self::assertSame('ab', $config->category);
        self::assertTrue($config->useEbookLibraryDir);

        $array = $config->toArray();
        self::assertArrayNotHasKey('completedPath', $array);
        self::assertArrayNotHasKey('remotePathFrom', $array);
        self::assertArrayNotHasKey('remotePathTo', $array);
        self::assertSame($config->category, TorrentClientConfig::fromArray($array)->category);
    }

    public function testRemoveOnCompleteDefaultsToTrueAndRoundTrips(): void
    {
        // Absent from the stored blob (older configs) → defaults to remove + delete.
        self::assertTrue(TorrentClientConfig::fromArray(['category' => 'ab'])->removeOnComplete);
        self::assertTrue(TorrentClientConfig::default()->removeOnComplete);

        // An explicit false (operator opted to keep seeding) survives a round trip.
        $kept = TorrentClientConfig::fromArray(['removeOnComplete' => false]);
        self::assertFalse($kept->removeOnComplete);
        self::assertFalse(TorrentClientConfig::fromArray($kept->toArray())->removeOnComplete);
    }
}
