<?php

declare(strict_types=1);

namespace App\Tests\Search\Mam;

use App\Search\Mam\MamTorrentLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The only thing standing between "the operator pasted something" and "we fetch a
 * torrent by id", so every accepted spelling and — more importantly — every
 * rejected one is pinned here.
 */
final class MamTorrentLinkTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testParsesATorrentReference(string $raw, ?int $expected, bool $allowBareId = false): void
    {
        self::assertSame($expected, MamTorrentLink::parse($raw, 'https://mam.example.test', $allowBareId));
    }

    /** @return iterable<string, array{0: string, 1: ?int, 2?: bool}> */
    public static function accepted(): iterable
    {
        yield 'canonical url'          => ['https://www.myanonamouse.net/t/182137', 182137];
        yield 'canonical without www'  => ['https://myanonamouse.net/t/182137', 182137];
        yield 'http scheme'            => ['http://www.myanonamouse.net/t/182137', 182137];
        yield 'tracker subdomain'      => ['https://t.myanonamouse.net/t/182137', 182137];
        yield 'configured base url'    => ['https://mam.example.test/t/182137', 182137];
        yield 'scheme-less host'       => ['www.myanonamouse.net/t/182137', 182137];
        yield 'protocol relative'      => ['//www.myanonamouse.net/t/182137', 182137];
        yield 'absolute path'          => ['/t/182137', 182137];
        yield 'bare path'              => ['t/182137', 182137];
        yield 'trailing slash'         => ['https://www.myanonamouse.net/t/182137/', 182137];
        yield 'title slug tail'        => ['https://www.myanonamouse.net/t/182137/red-rising', 182137];
        yield 'query string'           => ['https://www.myanonamouse.net/t/182137?foo=bar', 182137];
        yield 'fragment'               => ['https://www.myanonamouse.net/t/182137#comments', 182137];
        yield 'path with query'        => ['/t/182137?x=1', 182137];
        yield 'surrounding whitespace' => ['   https://www.myanonamouse.net/t/182137   ', 182137];

        // A bare number is only a torrent id when the caller says MyAnonamouse is
        // the selected source; otherwise it is a year, an ISBN or part of a title.
        yield 'bare id allowed'    => ['182137', 182137, true];
        yield 'bare id disallowed' => ['182137', null, false];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingElse(string $raw): void
    {
        self::assertNull(MamTorrentLink::parse($raw, 'https://mam.example.test', allowBareId: true));
    }

    /** @return iterable<string, array{0: string}> */
    public static function rejected(): iterable
    {
        yield 'empty'          => [''];
        yield 'whitespace'     => ['   '];
        yield 'plain title'    => ['Red Rising'];
        // The path shape alone is not enough: /t/123 on somebody else's site is
        // somebody else's torrent, and fetching it as a MAM id would be wrong.
        yield 'foreign host'   => ['https://example.com/t/182137'];
        yield 'wrong path'     => ['https://www.myanonamouse.net/tor/browse.php?id=182137'];
        yield 'no id'          => ['https://www.myanonamouse.net/t/'];
        yield 'non numeric id' => ['https://www.myanonamouse.net/t/abc'];
        yield 'zero id'        => ['https://www.myanonamouse.net/t/0'];
        yield 'id with letters' => ['/t/182137abc'];
        yield 'magnet'         => ['magnet:?xt=urn:btih:deadbeef'];
        yield 'isbn'           => ['9780345539786'];
        yield 'absurdly long'  => ['/t/' . str_repeat('9', 30)];
    }

    /**
     * The "you pasted a MAM link but picked another source" check has to fire even
     * for a host we would refuse to resolve — the point is to explain the shape,
     * not to accept it.
     */
    public function testLooksLikeTorrentReferenceIgnoresTheHost(): void
    {
        self::assertTrue(MamTorrentLink::looksLikeTorrentReference('https://example.com/t/182137'));
        self::assertTrue(MamTorrentLink::looksLikeTorrentReference('/t/182137'));
        self::assertFalse(MamTorrentLink::looksLikeTorrentReference('Red Rising'));
        self::assertFalse(MamTorrentLink::looksLikeTorrentReference('182137'));
    }

    /** With no configured base URL, MAM's own host still resolves. */
    public function testCanonicalHostWorksWithoutAConfiguredBaseUrl(): void
    {
        self::assertSame(182137, MamTorrentLink::parse('https://www.myanonamouse.net/t/182137'));
        self::assertNull(MamTorrentLink::parse('https://mam.example.test/t/182137'));
    }
}
