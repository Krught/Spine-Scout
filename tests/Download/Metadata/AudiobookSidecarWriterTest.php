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
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
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

    private function writer(?string $coverBytes): AudiobookSidecarWriter
    {
        $covers = $this->createStub(BookCoverProvider::class);
        $covers->method('originalCoverForBook')->willReturn($coverBytes === null ? null : [$coverBytes, 'image/jpeg']);

        return new AudiobookSidecarWriter($covers, new NullLogger());
    }
}
