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

    public function testWholeReleaseFolderIsPreservedWithStructure(): void
    {
        $source = $this->root . '/qbit/Dungeon Crawler Carl';
        mkdir($source . '/CD1', 0o775, true);
        mkdir($source . '/CD2', 0o775, true);
        file_put_contents($source . '/CD1/01.mp3', str_repeat('a', 2048));
        file_put_contents($source . '/CD1/02.mp3', str_repeat('b', 2048));
        file_put_contents($source . '/CD2/01.mp3', str_repeat('c', 2048));
        file_put_contents($source . '/book.nfo', 'nfo');
        file_put_contents($source . '/book.cue', 'cue');
        file_put_contents($source . '/artwork.jpg', str_repeat('i', 512));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'Matt Dinniman - Dungeon Crawler Carl', 'job-7');

        self::assertDirectoryExists($final);
        // Tree structure survives — no flattening, no CD1/CD2 collision.
        self::assertFileExists($final . '/CD1/01.mp3');
        self::assertFileExists($final . '/CD1/02.mp3');
        self::assertFileExists($final . '/CD2/01.mp3');
        // Companion files travel with the audio now.
        self::assertFileExists($final . '/book.nfo');
        self::assertFileExists($final . '/book.cue');
        self::assertFileExists($final . '/artwork.jpg');
        // Source is untouched (we copy so the torrent keeps seeding).
        self::assertFileExists($source . '/CD1/01.mp3');
    }

    public function testCoverNormalizationCopiesLargestImageToRoot(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source . '/scans', 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));
        file_put_contents($source . '/small.png', str_repeat('s', 100));
        file_put_contents($source . '/scans/front-scan.jpg', str_repeat('L', 900));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'Book', 'job-1');

        // Largest image copied to root as cover.<ext>, original kept.
        self::assertFileExists($final . '/cover.jpg');
        self::assertFileExists($final . '/scans/front-scan.jpg');
        self::assertSame(
            file_get_contents($final . '/scans/front-scan.jpg'),
            file_get_contents($final . '/cover.jpg'),
        );
        self::assertFileDoesNotExist($final . '/cover.png');
    }

    public function testCoverNormalizationSkipsWhenRootCoverExists(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));
        file_put_contents($source . '/cover.png', str_repeat('c', 100));
        // Bigger image elsewhere must not trigger an extra copy.
        file_put_contents($source . '/big.jpg', str_repeat('L', 900));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'Book', 'job-2');

        self::assertFileExists($final . '/cover.png');
        self::assertFileDoesNotExist($final . '/cover.jpg');
    }

    public function testCoverNormalizationAcceptsFolderJpegAsExistingCover(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));
        file_put_contents($source . '/Folder.JPEG', str_repeat('f', 100));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'Book', 'job-3');

        self::assertFileExists($final . '/Folder.JPEG');
        self::assertSame([], glob($final . '/cover.*'));
    }

    public function testCoverNormalizationDoesNothingWithoutImages(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));
        file_put_contents($source . '/book.nfo', 'nfo');

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'Book', 'job-4');

        self::assertSame([], glob($final . '/cover.*'));
    }

    public function testSingleFileM4bIsMoved(): void
    {
        $source = $this->root . '/qbit/book.m4b';
        mkdir(\dirname($source), 0o775, true);
        file_put_contents($source, str_repeat('x', 4096));

        $mover = new TorrentMover($this->root . '/staging');
        $final = $mover->move($source, $this->root . '/library', 'A Book', 'job-5');

        self::assertFileExists($final . '/book.m4b');
    }

    public function testThrowsWhenNoAudioFiles(): void
    {
        $source = $this->root . '/qbit/empty';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/book.nfo', 'no audio here');

        $mover = new TorrentMover($this->root . '/staging');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No audio files found');
        $mover->move($source, $this->root . '/library', 'X', 'job-6');
    }

    public function testBeforeFinalizeRunsOnStagedTreeBeforeFinalMove(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));

        $mover = new TorrentMover($this->root . '/staging');
        $seenStageDir = null;
        $stagedFileExisted = false;
        $finalExistedDuringCallback = true;
        $expectedFinal = $this->root . '/library/Book';

        $final = $mover->move(
            $source,
            $this->root . '/library',
            'Book',
            'job-8',
            function (string $stageDir) use (&$seenStageDir, &$stagedFileExisted, &$finalExistedDuringCallback, $expectedFinal): void {
                $seenStageDir = $stageDir;
                $stagedFileExisted = is_file($stageDir . '/01.mp3');
                $finalExistedDuringCallback = is_dir($expectedFinal);
            },
        );

        self::assertSame($expectedFinal, $final);
        self::assertNotNull($seenStageDir);
        self::assertStringStartsWith($this->root . '/staging/', $seenStageDir);
        self::assertTrue($stagedFileExisted);
        self::assertFalse($finalExistedDuringCallback);
    }

    public function testThrowingBeforeFinalizePropagatesAndCleansStaging(): void
    {
        $source = $this->root . '/qbit/book';
        mkdir($source, 0o775, true);
        file_put_contents($source . '/01.mp3', str_repeat('a', 1024));

        $mover = new TorrentMover($this->root . '/staging');

        try {
            $mover->move($source, $this->root . '/library', 'Book', 'job-9', static function (): void {
                throw new \DomainException('tag rewrite failed');
            });
            self::fail('Expected the callback exception to propagate.');
        } catch (\DomainException $e) {
            self::assertSame('tag rewrite failed', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/staging/job-9');
        self::assertDirectoryDoesNotExist($this->root . '/library/Book');
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
