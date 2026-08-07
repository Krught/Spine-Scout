<?php

declare(strict_types=1);

namespace App\Tests\Download\Metadata;

use App\Download\Metadata\AudiobookSidecarWriter;
use App\Entity\Book;
use App\Service\BookCoverProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AudiobookSidecarWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/spinescout_sidecar_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testWritesGrimmoryJsonEnvelopeAndCover(): void
    {
        $book = (new Book('hardcover', 'ext-1', 'The Way of Kings'))
            ->setAuthor('Brandon Sanderson, Co Author')
            ->setNarrator('Kate Reading, Michael Kramer')
            ->setSeries('The Stormlight Archive')
            ->setSeriesIndex('1')
            ->setSeriesTotal(10)
            ->setPublisher('Macmillan Audio')
            ->setPublishedDate('2010-08-31')
            ->setLanguage('en')
            ->setDescription('Epic fantasy.')
            ->setGenres(['Fantasy', 'Epic'])
            ->setIsbn('9780765326355')
            ->setIsbns(['9780765326355', '0765326353']);

        $this->writer('JPEGBYTES')->write($this->dir, 'Brandon Sanderson - The Way of Kings', $book);

        $jsonPath = $this->dir . '/Brandon Sanderson - The Way of Kings.metadata.json';
        $coverPath = $this->dir . '/Brandon Sanderson - The Way of Kings.cover.jpg';
        self::assertFileExists($jsonPath);
        self::assertFileExists($coverPath);
        self::assertSame('JPEGBYTES', file_get_contents($coverPath));

        $data = json_decode((string) file_get_contents($jsonPath), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $data['version']);
        self::assertSame('spinescout', $data['generatedBy']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['generatedAt']);

        $m = $data['metadata'];
        self::assertSame('The Way of Kings', $m['title']);
        self::assertSame(['Brandon Sanderson', 'Co Author'], $m['authors']);
        self::assertSame('Macmillan Audio', $m['publisher']);
        self::assertSame('2010-08-31', $m['publishedDate']);
        self::assertSame('Epic fantasy.', $m['description']);
        self::assertSame('9780765326355', $m['isbn13']);
        self::assertSame('0765326353', $m['isbn10']);
        self::assertSame(['Fantasy', 'Epic'], $m['categories']);
        self::assertSame('en', $m['language']);
        self::assertSame('The Stormlight Archive', $m['seriesName']);
        self::assertSame('1', $m['seriesNumber']);
        self::assertSame(10, $m['seriesTotal']);
        self::assertSame('Kate Reading, Michael Kramer', $m['narrator']);
    }

    public function testOmitsNullFieldsAndSkipsAbsentCover(): void
    {
        $book = new Book('hardcover', 'ext-2', 'Bare Title');

        $this->writer(null)->write($this->dir, 'Bare Title', $book);

        $jsonPath = $this->dir . '/Bare Title.metadata.json';
        self::assertFileExists($jsonPath);
        self::assertFileDoesNotExist($this->dir . '/Bare Title.cover.jpg');

        $m = json_decode((string) file_get_contents($jsonPath), true, 512, \JSON_THROW_ON_ERROR)['metadata'];
        self::assertSame(['title'], array_keys($m));
        self::assertSame('Bare Title', $m['title']);
    }

    public function testOverwritesExistingSidecar(): void
    {
        $jsonPath = $this->dir . '/Bare Title.metadata.json';
        file_put_contents($jsonPath, 'STALE');

        $this->writer(null)->write($this->dir, 'Bare Title', new Book('hardcover', 'ext-3', 'Bare Title'));

        self::assertStringContainsString('"title": "Bare Title"', (string) file_get_contents($jsonPath));
    }

    public function testSingleFileAlbumWritesSidecarNextToTheAudioFile(): void
    {
        $album = $this->dir . '/The Way of Kings';
        mkdir($album, 0o775, true);
        file_put_contents($album . '/The Way of Kings.m4b', 'AUDIO');
        file_put_contents($album . '/cover.jpg', 'ART'); // companion file must not count as audio

        $this->writer('JPEGBYTES')->writeForAlbum($album, new Book('hardcover', 'ext-4', 'The Way of Kings'));

        // Single-file audiobook: sidecar goes INSIDE, named after the file minus extension.
        self::assertFileExists($album . '/The Way of Kings.metadata.json');
        self::assertFileExists($album . '/The Way of Kings.cover.jpg');
        self::assertFileDoesNotExist($this->dir . '/The Way of Kings.metadata.json');
    }

    public function testSingleFileInNestedSubdirGetsSidecarNextToTheFile(): void
    {
        $album = $this->dir . '/Album';
        mkdir($album . '/CD1', 0o775, true);
        file_put_contents($album . '/CD1/Book Part One.mp3', 'AUDIO');

        $this->writer(null)->writeForAlbum($album, new Book('hardcover', 'ext-5', 'Book'));

        self::assertFileExists($album . '/CD1/Book Part One.metadata.json');
        self::assertFileDoesNotExist($album . '/Book Part One.metadata.json');
        self::assertFileDoesNotExist($this->dir . '/Album.metadata.json');
    }

    public function testMultiFileAlbumWritesSidecarBesideTheAlbumFolder(): void
    {
        $album = $this->dir . '/Brandon Sanderson - The Way of Kings';
        mkdir($album, 0o775, true);
        file_put_contents($album . '/Part 1.mp3', 'AUDIO');
        file_put_contents($album . '/Part 2.mp3', 'AUDIO');

        $this->writer(null)->writeForAlbum($album, new Book('hardcover', 'ext-6', 'The Way of Kings'));

        // Folder-based audiobook: sidecar goes BESIDE the folder, named after it.
        self::assertFileExists($this->dir . '/Brandon Sanderson - The Way of Kings.metadata.json');
        self::assertFileDoesNotExist($album . '/Brandon Sanderson - The Way of Kings.metadata.json');
    }

    public function testAlbumFolderNameWithDotIsTruncatedAtLastDot(): void
    {
        $album = $this->dir . '/Series Vol. 1';
        mkdir($album, 0o775, true);
        file_put_contents($album . '/Part 1.mp3', 'AUDIO');
        file_put_contents($album . '/Part 2.mp3', 'AUDIO');

        $this->writer(null)->writeForAlbum($album, new Book('hardcover', 'ext-7', 'Series Vol. 1'));

        // Grimmory truncates the folder name at its LAST dot when resolving the sidecar.
        self::assertFileExists($this->dir . '/Series Vol.metadata.json');
        self::assertFileDoesNotExist($this->dir . '/Series Vol. 1.metadata.json');
    }

    public function testAlbumWithNoAudioFilesWritesNothing(): void
    {
        $album = $this->dir . '/Empty Album';
        mkdir($album, 0o775, true);
        file_put_contents($album . '/notes.nfo', 'NFO');
        file_put_contents($album . '/cover.jpg', 'ART');

        $this->writer('JPEGBYTES')->writeForAlbum($album, new Book('hardcover', 'ext-8', 'Empty Album'));

        self::assertFileDoesNotExist($this->dir . '/Empty Album.metadata.json');
        self::assertFileDoesNotExist($album . '/Empty Album.metadata.json');
        self::assertFileDoesNotExist($album . '/notes.metadata.json');
    }

    public function testNonJpegCoverIsTranscodedToJpeg(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is not available on this PHP (present in the app image, where transcoding runs).');
        }

        $png = imagecreatetruecolor(4, 4);
        self::assertNotFalse($png);
        imagefill($png, 0, 0, (int) imagecolorallocate($png, 200, 40, 40));
        ob_start();
        imagepng($png);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($png);
        self::assertStringStartsWith("\x89PNG", $pngBytes);

        $this->writer($pngBytes, 'image/png')->write($this->dir, 'Png Cover', new Book('hardcover', 'ext-9', 'Png Cover'));

        $coverPath = $this->dir . '/Png Cover.cover.jpg';
        self::assertFileExists($coverPath);
        $written = (string) file_get_contents($coverPath);
        self::assertStringStartsWith("\xFF\xD8", $written, 'cover must be re-encoded as JPEG');
    }

    private function writer(?string $coverBytes, string $coverMime = 'image/jpeg'): AudiobookSidecarWriter
    {
        $covers = $this->createStub(BookCoverProvider::class);
        $covers->method('originalCoverForBook')->willReturn($coverBytes === null ? null : [$coverBytes, $coverMime]);

        return new AudiobookSidecarWriter($covers, new NullLogger());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            $item->isDir() && !$item->isLink() ? $this->removeTree($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
