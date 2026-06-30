<?php

declare(strict_types=1);

namespace App\Tests\Download\Torrent;

use App\Download\Torrent\TorrentMover;
use PHPUnit\Framework\TestCase;

final class TorrentMoverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tmover-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testMovesFolderOfAudioFilesAndSkipsJunk(): void
    {
        $source = $this->root . '/qbit/Dungeon Crawler Carl';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 2048));
        file_put_contents($source . '/02.mp3', str_repeat('b', 2048));
        file_put_contents($source . '/cover.jpg', 'img');
        file_put_contents($source . '/notes.nfo', 'nfo');

        $mover = new TorrentMover($this->root . '/staging');
        $dest = $this->root . '/library';

        $final = $mover->move($source, $dest, 'Matt Dinniman - Dungeon Crawler Carl', 'job-7');

        self::assertDirectoryExists($final);
        self::assertFileExists($final . '/01.mp3');
        self::assertFileExists($final . '/02.mp3');
        // Non-audio junk is left behind.
        self::assertFileDoesNotExist($final . '/cover.jpg');
        self::assertFileDoesNotExist($final . '/notes.nfo');
        // Source is untouched (we copy so the torrent keeps seeding).
        self::assertFileExists($source . '/01.mp3');
    }

    public function testSingleFileM4bIsMoved(): void
    {
        $source = $this->root . '/qbit/book.m4b';
        mkdir(\dirname($source), 0o775, true);
        file_put_contents($source, str_repeat('x', 4096));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'A Book', 'job-1');

        self::assertFileExists($final . '/book.m4b');
    }

    public function testThrowsWhenNoAudioFiles(): void
    {
        $source = $this->root . '/qbit/empty';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/readme.txt', 'no audio here');

        $mover = new TorrentMover($this->root . '/staging');

        $this->expectException(\RuntimeException::class);
        $mover->move($source, $this->root . '/library', 'X', 'job-2');
    }

    public function testAudioFilesFindsRecursivelyAndSkipsNonAudio(): void
    {
        $dir = $this->root . '/pack';
        mkdir($dir . '/disc1', 0o775, true);
        file_put_contents($dir . '/disc1/a.flac', 'a');
        file_put_contents($dir . '/b.opus', 'b');
        file_put_contents($dir . '/art.png', 'p');

        $found = TorrentMover::audioFiles($dir);

        self::assertCount(2, $found);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
