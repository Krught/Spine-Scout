<?php

declare(strict_types=1);

namespace App\Tests\Download\Metadata;

use App\Download\Metadata\AudiobookTagWriter;
use App\Entity\Book;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the writer against a FAKE tone executable (a PHP script standing in
 * for the real binary): it appends every argv to a log, serves canned dump JSON
 * per call, and can be told to corrupt the tagged file — so the tests cover the
 * fill/skip decisions and the backup-verify-swap safety net without requiring
 * tone on the host.
 */
final class AudiobookTagWriterTest extends TestCase
{
    private string $baseDir;
    private string $fakeDir;
    private string $audioDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/spinescout_tagwriter_' . bin2hex(random_bytes(6));
        $this->fakeDir = $this->baseDir . '/fake';
        $this->audioDir = $this->baseDir . '/album';
        mkdir($this->fakeDir, 0o775, true);
        mkdir($this->audioDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
    }

    public function testFillsOnlyMissingFieldsAndRemovesBackup(): void
    {
        $file = $this->audioFile('book.m4b', 'ORIGINAL BYTES');
        // File already has an artist; album/albumArtist/group are absent.
        $this->cannedDump(['artist' => 'Existing Artist']);

        $book = (new Book('hardcover', 'ext-1', 'The Title'))
            ->setAuthor('Author A')
            ->setSeries('Series S');

        $this->writer()->fillMissingTags($this->audioDir, $book);

        $tag = $this->onlyTagCall();
        self::assertSame($file, $tag[1]);
        self::assertFlagValue($tag, '--meta-album', 'The Title');
        self::assertFlagValue($tag, '--meta-album-artist', 'Author A');
        self::assertFlagValue($tag, '--meta-group', 'Series S');
        self::assertContains('--assume-yes', $tag);
        self::assertNotContains('--meta-artist', $tag, 'existing artist must never be overwritten');

        self::assertFileDoesNotExist($file . '.spinescout.bak');
        self::assertSame('ORIGINAL BYTES', file_get_contents($file));
    }

    public function testMultiFileAudiobookNeverGetsTrackNumbers(): void
    {
        $this->audioFile('part1.mp3', 'P1');
        $this->audioFile('part2.mp3', 'P2');
        $this->cannedDump([]);

        $book = (new Book('hardcover', 'ext-2', 'The Title'))
            ->setSeries('Series S')
            ->setSeriesIndex('3');

        $this->writer()->fillMissingTags($this->audioDir, $book);

        $tagCalls = $this->tagCalls();
        self::assertCount(2, $tagCalls);
        foreach ($tagCalls as $tag) {
            self::assertNotContains('--meta-track-number', $tag, 'multi-file track order must never be touched');
            self::assertFlagValue($tag, '--meta-group', 'Series S');
        }
    }

    public function testSingleFileAudiobookGetsSeriesIndexAsTrackNumber(): void
    {
        $file = $this->audioFile('book.m4b', 'BYTES');
        $this->cannedDump([]);

        $book = (new Book('hardcover', 'ext-3', 'The Title'))
            ->setSeries('Series S')
            ->setSeriesIndex('3');

        $this->writer()->fillMissingTags($this->audioDir, $book);

        self::assertFlagValue($this->onlyTagCall(), '--meta-track-number', '3');
        self::assertFileDoesNotExist($file . '.spinescout.bak');
    }

    public function testVerifyFailureRestoresOriginalBytesAndLeavesNoBackup(): void
    {
        $file = $this->audioFile('book.m4b', 'ORIGINAL BYTES');
        // Pre-dump reports 60s; the fake corrupts the file on tag; post-dump
        // reports 90s — outside the 2s tolerance, so the swap must roll back.
        file_put_contents($this->fakeDir . '/dump_book.m4b_1.json', $this->dumpJson(60000.0, []));
        file_put_contents($this->fakeDir . '/dump_book.m4b_2.json', $this->dumpJson(90000.0, []));
        file_put_contents($this->fakeDir . '/tag_writes', 'CORRUPTED');

        $book = (new Book('hardcover', 'ext-4', 'The Title'))->setAuthor('Author A');

        $this->writer()->fillMissingTags($this->audioDir, $book);

        self::assertSame('ORIGINAL BYTES', file_get_contents($file), 'backup must be restored over the corrupted file');
        self::assertFileDoesNotExist($file . '.spinescout.bak');
    }

    public function testNothingMissingMeansNoWriteAtAll(): void
    {
        $file = $this->audioFile('book.m4b', 'BYTES');
        $this->cannedDump(['album' => 'Already Tagged']);

        // The book only knows the title, which the file already carries as album.
        $this->writer()->fillMissingTags($this->audioDir, new Book('hardcover', 'ext-5', 'The Title'));

        self::assertSame([], $this->tagCalls());
        self::assertFileDoesNotExist($file . '.spinescout.bak');
        self::assertSame('BYTES', file_get_contents($file));
    }

    public function testMissingBinaryIsANoOpWithoutErrors(): void
    {
        $file = $this->audioFile('book.m4b', 'BYTES');

        $writer = new AudiobookTagWriter($this->baseDir . '/does-not-exist/tone');
        self::assertFalse($writer->isAvailable());

        $writer->fillMissingTags($this->audioDir, (new Book('hardcover', 'ext-6', 'The Title'))->setAuthor('A'));

        self::assertSame('BYTES', file_get_contents($file));
        self::assertFileDoesNotExist($file . '.spinescout.bak');
    }

    // ---- fake tone -----------------------------------------------------------

    private function writer(): AudiobookTagWriter
    {
        $script = $this->fakeDir . '/tone';
        file_put_contents($script, <<<'PHP'
            #!/usr/bin/env php
            <?php
            $dir = __DIR__;
            file_put_contents($dir . '/argv.log', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
            if (in_array('--version', $argv, true)) {
                echo "0.2.5\n";
                exit(0);
            }
            $cmd = $argv[1] ?? '';
            $file = $argv[2] ?? '';
            $base = basename($file);
            if ($cmd === 'dump') {
                $countFile = $dir . '/dumpcount_' . $base;
                $n = (is_file($countFile) ? (int) file_get_contents($countFile) : 0) + 1;
                file_put_contents($countFile, (string) $n);
                foreach ([$dir . '/dump_' . $base . '_' . $n . '.json', $dir . '/dump_' . $base . '.json'] as $candidate) {
                    if (is_file($candidate)) {
                        echo file_get_contents($candidate);
                        exit(0);
                    }
                }
                exit(1);
            }
            if ($cmd === 'tag') {
                if (is_file($dir . '/tag_writes')) {
                    file_put_contents($file, file_get_contents($dir . '/tag_writes'));
                }
                exit(0);
            }
            exit(1);
            PHP);
        chmod($script, 0o755);

        return new AudiobookTagWriter($script);
    }

    /** Serve the same dump JSON for every dump call on every file. */
    private function cannedDump(array $meta, float $durationMs = 60000.0): void
    {
        foreach (glob($this->audioDir . '/*') ?: [] as $path) {
            file_put_contents($this->fakeDir . '/dump_' . basename($path) . '.json', $this->dumpJson($durationMs, $meta));
        }
    }

    /** The tone 0.2.5 dump --format json shape: audio.duration in ms, tags in meta. */
    private function dumpJson(float $durationMs, array $meta): string
    {
        return json_encode([
            'audio' => ['duration' => $durationMs, 'format' => 'MPEG-4 Part 14'],
            'meta'  => $meta === [] ? new \stdClass() : $meta,
        ], \JSON_THROW_ON_ERROR);
    }

    private function audioFile(string $name, string $content): string
    {
        $path = $this->audioDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /** @return list<list<string>> argv (minus binary) of every `tone tag` invocation */
    private function tagCalls(): array
    {
        $log = $this->fakeDir . '/argv.log';
        if (!is_file($log)) {
            return [];
        }

        $calls = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line === '') {
                continue;
            }
            $argv = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (($argv[0] ?? null) === 'tag') {
                $calls[] = $argv;
            }
        }

        return $calls;
    }

    /** @return list<string> */
    private function onlyTagCall(): array
    {
        $calls = $this->tagCalls();
        self::assertCount(1, $calls);

        return $calls[0];
    }

    /** @param list<string> $argv */
    private static function assertFlagValue(array $argv, string $flag, string $expected): void
    {
        $pos = array_search($flag, $argv, true);
        self::assertNotFalse($pos, "expected flag {$flag} to be passed");
        self::assertSame($expected, $argv[$pos + 1] ?? null, "value for {$flag}");
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
