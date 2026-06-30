<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Metadata\AudiobookSidecarWriter;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Message\RewriteAudiobookSidecar;
use App\MessageHandler\RewriteAudiobookSidecarHandler;
use App\Service\BookCoverProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RewriteAudiobookSidecarHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spinescout_rewrite_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($this->root);
    }

    public function testRewritesSidecarBesideTheAlbumFolder(): void
    {
        $album = $this->root . '/Brandon Sanderson - The Way of Kings';
        mkdir($album, 0o775, true);

        $book = (new Book('hardcover', 'ext-1', 'The Way of Kings'))
            ->setAuthor('Brandon Sanderson')
            ->setNarrator('Kate Reading');
        $job = $this->completedAudiobookJob($book, $album);

        $this->handler($job, 'JPEGBYTES')(new RewriteAudiobookSidecar(7));

        // Sidecar lands BESIDE the album folder (in $this->root), named after it.
        self::assertFileExists($this->root . '/Brandon Sanderson - The Way of Kings.metadata.json');
        self::assertFileExists($this->root . '/Brandon Sanderson - The Way of Kings.cover.jpg');
        // The folder itself stays free of the sidecar.
        self::assertFileDoesNotExist($album . '/Brandon Sanderson - The Way of Kings.metadata.json');

        $meta = json_decode((string) file_get_contents($this->root . '/Brandon Sanderson - The Way of Kings.metadata.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('The Way of Kings', $meta['metadata']['title']);
        self::assertSame('Kate Reading', $meta['metadata']['narrator']);
    }

    public function testSkipsWhenJobIsNotAudiobook(): void
    {
        $album = $this->root . '/Some Folder';
        mkdir($album, 0o775, true);

        $job = $this->completedAudiobookJob(new Book('hardcover', 'ext-2', 'An Ebook'), $album);
        $job->getBookRequest()?->setAudiobook(false);

        $this->handler($job, 'JPEGBYTES')(new RewriteAudiobookSidecar(7));

        self::assertSame([], glob($this->root . '/*.metadata.json') ?: []);
    }

    public function testSkipsWhenAlbumFolderIsGone(): void
    {
        $job = $this->completedAudiobookJob(new Book('hardcover', 'ext-3', 'Gone'), $this->root . '/missing-folder');

        $this->handler($job, 'JPEGBYTES')(new RewriteAudiobookSidecar(7));

        self::assertSame([], glob($this->root . '/*.metadata.json') ?: []);
    }

    public function testSkipsWhenJobNotComplete(): void
    {
        $album = $this->root . '/Pending Album';
        mkdir($album, 0o775, true);

        $job = $this->completedAudiobookJob(new Book('hardcover', 'ext-4', 'Pending'), $album);
        $job->setStatus(DownloadJob::STATUS_DOWNLOADING);

        $this->handler($job, 'JPEGBYTES')(new RewriteAudiobookSidecar(7));

        self::assertSame([], glob($this->root . '/*.metadata.json') ?: []);
    }

    private function completedAudiobookJob(Book $book, string $albumPath): DownloadJob
    {
        $request = (new BookRequest(new User('admin'), $book))->setAudiobook(true);
        $job = new DownloadJob('grimmory', 'ext', 'torrent', $request);
        $job->setStatus(DownloadJob::STATUS_COMPLETE)->setFilePath($albumPath);

        return $job;
    }

    private function handler(DownloadJob $job, ?string $coverBytes): RewriteAudiobookSidecarHandler
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($job);

        $covers = $this->createStub(BookCoverProvider::class);
        $covers->method('originalCoverForBook')->willReturn($coverBytes === null ? null : [$coverBytes, 'image/jpeg']);

        return new RewriteAudiobookSidecarHandler(
            $em,
            new AudiobookSidecarWriter($covers, new NullLogger()),
            new NullLogger(),
        );
    }

    private function rrmdir(string $dir): void
    {
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
