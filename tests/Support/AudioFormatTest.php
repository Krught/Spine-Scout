<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\AudioFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudioFormatTest extends TestCase
{
    #[DataProvider('formats')]
    public function testIsAudio(?string $format, bool $expected): void
    {
        self::assertSame($expected, AudioFormat::isAudio($format));
    }

    /** @return iterable<string, array{0: ?string, 1: bool}> */
    public static function formats(): iterable
    {
        yield 'm4b is audio'        => ['m4b', true];
        yield 'mp3 is audio'        => ['mp3', true];
        yield 'mpeg mime sub'       => ['mpeg', true];
        yield 'aax (audible)'       => ['aax', true];
        yield 'uppercase normalized'=> ['MP3', true];
        yield 'epub is not audio'   => ['epub', false];
        yield 'pdf is not audio'    => ['pdf', false];
        yield 'cbz is not audio'    => ['cbz', false];
        yield 'null is not audio'   => [null, false];
        yield 'empty is not audio'  => ['', false];
    }
}
