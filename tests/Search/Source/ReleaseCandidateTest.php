<?php

declare(strict_types=1);

namespace App\Tests\Search\Source;

use App\Search\Source\ReleaseCandidate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseCandidateTest extends TestCase
{
    /**
     * Sources classify each result from its file extension, so a search that
     * asked for audiobooks but got an epub row still labels it an ebook (and
     * vice-versa) — the label describes what actually came back.
     */
    #[DataProvider('formatProvider')]
    public function testContentTypeForFormat(?string $format, string $expected): void
    {
        self::assertSame($expected, ReleaseCandidate::contentTypeForFormat($format));
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function formatProvider(): iterable
    {
        yield 'm4b'            => ['m4b', ReleaseCandidate::CONTENT_AUDIOBOOK];
        yield 'mp3'            => ['mp3', ReleaseCandidate::CONTENT_AUDIOBOOK];
        yield 'uppercase M4B'  => ['M4B', ReleaseCandidate::CONTENT_AUDIOBOOK];
        yield 'flac'           => ['flac', ReleaseCandidate::CONTENT_AUDIOBOOK];
        yield 'audible aax'    => ['aax', ReleaseCandidate::CONTENT_AUDIOBOOK];
        yield 'epub'           => ['epub', ReleaseCandidate::CONTENT_EBOOK];
        yield 'pdf'            => ['pdf', ReleaseCandidate::CONTENT_EBOOK];
        yield 'unknown format' => ['xyz', ReleaseCandidate::CONTENT_EBOOK];
        yield 'null'           => [null, ReleaseCandidate::CONTENT_EBOOK];
        yield 'empty string'   => ['', ReleaseCandidate::CONTENT_EBOOK];
    }
}
