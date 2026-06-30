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
}
