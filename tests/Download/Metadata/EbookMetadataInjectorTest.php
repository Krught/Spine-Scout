<?php

declare(strict_types=1);

namespace App\Tests\Download\Metadata;

use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Metadata\EpubMetadataWriter;
use App\Entity\Book;
use App\Service\AppSettingsProvider;
use App\Service\BookCoverProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EbookMetadataInjectorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/spinescout_inj_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTogglingOffLeavesFileUntouched(): void
    {
        file_put_contents($this->file, 'ORIGINAL');
        $injector = $this->injector(enabled: false);

        self::assertFalse($injector->inject($this->file, $this->book(), 'epub'));
        self::assertSame('ORIGINAL', file_get_contents($this->file));
    }

    public function testNonEpubFormatIsSkipped(): void
    {
        file_put_contents($this->file, 'PDFDATA');
        $injector = $this->injector(enabled: true);

        self::assertFalse($injector->inject($this->file, $this->book(), 'pdf'));
        self::assertSame('PDFDATA', file_get_contents($this->file));
    }

    public function testWriterFailureIsSwallowedBestEffort(): void
    {
        // Not a valid zip → EpubMetadataWriter throws; the injector must absorb it.
        file_put_contents($this->file, 'NOT A ZIP');
        $injector = $this->injector(enabled: true);

        self::assertFalse($injector->inject($this->file, $this->book(), 'epub'));
        self::assertSame('NOT A ZIP', file_get_contents($this->file));
    }

    private function injector(bool $enabled): EbookMetadataInjector
    {
        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('isMetadataOverwriteEnabled')->willReturn($enabled);

        $covers = $this->createStub(BookCoverProvider::class);
        $covers->method('originalCoverForBook')->willReturn(null);

        return new EbookMetadataInjector($settings, new EpubMetadataWriter(), $covers, new NullLogger());
    }

    private function book(): Book
    {
        return (new Book('hardcover', 'ext-1', 'Title'))->setAuthor('Author');
    }
}
