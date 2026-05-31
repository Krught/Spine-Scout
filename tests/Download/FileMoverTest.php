<?php

declare(strict_types=1);

namespace App\Tests\Download;

use App\Download\FileMover;
use PHPUnit\Framework\TestCase;

final class FileMoverTest extends TestCase
{
    private string $root;
    private FileMover $mover;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spinescout_mover_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/staging', 0o775, true);
        $this->mover = new FileMover();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testMovesFileIntoOutputDirectory(): void
    {
        $staged = $this->stage('abc');
        $dest = $this->root . '/out';

        $final = $this->mover->move($staged, $dest, 'Author - Title (2014).epub');

        self::assertSame($dest . '/Author - Title (2014).epub', $final);
        self::assertFileExists($final);
        self::assertFileDoesNotExist($staged);
        self::assertSame('abc', file_get_contents($final));
    }

    public function testCreatesOutputDirectoryIfMissing(): void
    {
        $final = $this->mover->move($this->stage('x'), $this->root . '/deep/nested/out', 'f.epub');
        self::assertFileExists($final);
    }

    public function testCollisionGetsSuffixedName(): void
    {
        $dest = $this->root . '/out';
        mkdir($dest, 0o775, true);
        file_put_contents($dest . '/Book.epub', 'existing');

        $final = $this->mover->move($this->stage('new'), $dest, 'Book.epub');

        self::assertSame($dest . '/Book (1).epub', $final);
        self::assertSame('existing', file_get_contents($dest . '/Book.epub'));
        self::assertSame('new', file_get_contents($final));
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mover->move($this->root . '/staging/nope', $this->root . '/out', 'f.epub');
    }

    public function testEmptyDestinationThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mover->move($this->stage('x'), '   ', 'f.epub');
    }

    private function stage(string $contents): string
    {
        $path = $this->root . '/staging/' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
