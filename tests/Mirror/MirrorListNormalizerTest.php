<?php

declare(strict_types=1);

namespace App\Tests\Mirror;

use App\Mirror\MirrorListNormalizer;
use PHPUnit\Framework\TestCase;

final class MirrorListNormalizerTest extends TestCase
{
    public function testTrimsWhitespaceAndDropsBlanks(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame(
            ['https://a.example', 'https://b.example'],
            $n->normalize(['  https://a.example  ', '', '   ', 'https://b.example']),
        );
    }

    public function testDefaultsToHttpsWhenSchemeMissing(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame(['https://a.example'], $n->normalize(['a.example']));
    }

    public function testPreservesExplicitHttp(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame(['http://a.example'], $n->normalize(['http://a.example']));
    }

    public function testStripsTrailingSlash(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame(['https://a.example'], $n->normalize(['https://a.example/']));
    }

    public function testDedupesWhilePreservingOrder(): void
    {
        $n = new MirrorListNormalizer();
        $out = $n->normalize([
            'https://a.example',
            'https://b.example/',
            'a.example',                  // same as #0 after normalization
            'HTTPS://A.EXAMPLE',          // case-insensitive dedupe
        ]);
        self::assertSame(['https://a.example', 'https://b.example'], $out);
    }

    public function testIgnoresNonStringEntries(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame(['https://a.example'], $n->normalize(['https://a.example', null, 42, [], new \stdClass()]));
    }

    public function testNormalizeBlobSplitsOnNewlinesTabsAndCommas(): void
    {
        $n = new MirrorListNormalizer();
        $blob = "https://a.example\n  b.example/  \t http://c.example , a.example";
        self::assertSame(
            ['https://a.example', 'https://b.example', 'http://c.example'],
            $n->normalizeBlob($blob),
        );
    }

    public function testNormalizeBlobEmptyString(): void
    {
        $n = new MirrorListNormalizer();
        self::assertSame([], $n->normalizeBlob("   \n\t  "));
    }
}
