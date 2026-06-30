<?php

declare(strict_types=1);

namespace App\Tests\Integration\Prowlarr;

use App\Integration\Prowlarr\ProwlarrClient;
use App\Search\Source\ReleaseCandidate;
use PHPUnit\Framework\TestCase;

final class ProwlarrClientMapTest extends TestCase
{
    public function testMapsTorrentRowToAudiobookCandidate(): void
    {
        $rows = [[
            'guid'        => 'abc-123',
            'title'       => 'Dungeon Crawler Carl [M4B] 2020',
            'size'        => 734003200,
            'seeders'     => 42,
            'leechers'    => 3,
            'grabs'       => 17,
            'protocol'    => 'torrent',
            'indexer'     => 'MyAnonamouse',
            'magnetUrl'   => 'magnet:?xt=urn:btih:deadbeef',
            'infoUrl'     => 'https://indexer.example/details/1',
            'categories'  => [3030],
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertCount(1, $out);
        $c = $out[0];
        self::assertSame('prowlarr', $c->source);
        self::assertSame('abc-123', $c->sourceId);
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $c->protocol);
        self::assertSame(ReleaseCandidate::CONTENT_AUDIOBOOK, $c->contentType);
        self::assertSame(42, $c->seeders);
        self::assertSame(734003200, $c->sizeBytes);
        self::assertSame('magnet:?xt=urn:btih:deadbeef', $c->downloadUrl);
        self::assertSame('MyAnonamouse', $c->indexer);
        self::assertSame('m4b', $c->format);
        self::assertSame('2020', $c->year);
    }

    public function testFallsBackFromMagnetToDownloadUrl(): void
    {
        $rows = [[
            'guid'        => 'g1',
            'title'       => 'Some Audiobook',
            'protocol'    => 'torrent',
            'downloadUrl' => 'https://indexer.example/file.torrent',
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertCount(1, $out);
        self::assertSame('https://indexer.example/file.torrent', $out[0]->downloadUrl);
    }

    public function testMapsEbookContentTypeAndFormat(): void
    {
        $rows = [[
            'guid'      => 'e1',
            'title'     => 'Red Rising EPUB retail',
            'protocol'  => 'torrent',
            'magnetUrl' => 'magnet:?xt=urn:btih:abc',
            'seeders'   => 5,
        ]];

        $out = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK);

        self::assertCount(1, $out);
        self::assertSame(ReleaseCandidate::CONTENT_EBOOK, $out[0]->contentType);
        self::assertSame('epub', $out[0]->format);
    }

    public function testSkipsNonTorrentAndLinklessRows(): void
    {
        $rows = [
            ['title' => 'Usenet release', 'protocol' => 'usenet', 'downloadUrl' => 'https://x/y.nzb'],
            ['title' => 'No link torrent', 'protocol' => 'torrent'],
            ['title' => '', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:1'],
            'not-an-array',
        ];

        self::assertSame([], ProwlarrClient::mapResults($rows));
    }
}
