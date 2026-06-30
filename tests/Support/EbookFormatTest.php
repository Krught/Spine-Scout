<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\EbookFormat;
use PHPUnit\Framework\TestCase;

final class EbookFormatTest extends TestCase
{
    public function testIsEbook(): void
    {
        self::assertTrue(EbookFormat::isEbook('epub'));
        self::assertTrue(EbookFormat::isEbook('PDF'));
        self::assertTrue(EbookFormat::isEbook('azw3'));
        self::assertFalse(EbookFormat::isEbook('mp3'));
        self::assertFalse(EbookFormat::isEbook(null));
        self::assertFalse(EbookFormat::isEbook(''));
    }

    public function testRankPrefersEpubAndSortsUnknownLast(): void
    {
        self::assertLessThan(EbookFormat::rank('pdf'), EbookFormat::rank('epub'));
        self::assertLessThan(EbookFormat::rank('xyz'), EbookFormat::rank('txt'));
        self::assertSame(\count(EbookFormat::EXTENSIONS), EbookFormat::rank('unknown'));
    }
}
