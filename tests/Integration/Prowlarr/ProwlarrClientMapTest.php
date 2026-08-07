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
        self::assertSame(3, $c->extra['leechers']);
        self::assertSame([], $c->extra['flags']);
        self::assertSame([3030], $c->extra['categoryIds']);
        self::assertSame([], $c->extra['categories']);
        self::assertSame(ReleaseCandidate::CONTENT_AUDIOBOOK, $c->extra['type']);
        self::assertNull($c->extra['publishDate']);
    }

    public function testMapsCategoryObjectsToIdsAndNames(): void
    {
        $rows = [[
            'guid'       => 'c1',
            'title'      => 'Categorized release',
            'protocol'   => 'torrent',
            'magnetUrl'  => 'magnet:?xt=urn:btih:c1',
            'categories' => [
                ['id' => 3000, 'name' => 'Audio'],
                ['id' => 3030, 'name' => ' Audio/Audiobook '],
            ],
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame([3000, 3030], $out[0]->extra['categoryIds']);
        self::assertSame(['Audio', 'Audio/Audiobook'], $out[0]->extra['categories']);
        self::assertSame(ReleaseCandidate::CONTENT_AUDIOBOOK, $out[0]->extra['type']);
    }

    public function testFlattensNestedSubCategoriesAndDedupes(): void
    {
        $rows = [[
            'guid'       => 'c2',
            'title'      => 'Nested categories',
            'protocol'   => 'torrent',
            'magnetUrl'  => 'magnet:?xt=urn:btih:c2',
            'categories' => [
                ['id' => 7000, 'name' => 'Books', 'subCategories' => [
                    ['id' => 7020, 'name' => 'Books/EBook'],
                    ['id' => 7000, 'name' => 'Books'],
                ]],
                ['id' => 7020, 'name' => 'Books/EBook'],
            ],
        ]];

        $out = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK);

        self::assertSame([7000, 7020], $out[0]->extra['categoryIds']);
        self::assertSame(['Books', 'Books/EBook'], $out[0]->extra['categories']);
        self::assertSame(ReleaseCandidate::CONTENT_EBOOK, $out[0]->extra['type']);
    }

    public function testCategoriesAreEmptyWhenAbsentOrMalformed(): void
    {
        $rows = [
            ['guid' => 'c3', 'title' => 'No categories', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:c3'],
            ['guid' => 'c4', 'title' => 'Scalar categories', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:c4', 'categories' => 3030],
            ['guid' => 'c5', 'title' => 'Junk categories', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:c5', 'categories' => [['name' => 'Nameless'], 'x', null]],
        ];

        $out = ProwlarrClient::mapResults($rows);

        foreach ($out as $c) {
            self::assertSame([], $c->extra['categoryIds']);
            self::assertNull($c->extra['type']);
        }
        self::assertSame([], $out[0]->extra['categories']);
        self::assertSame([], $out[1]->extra['categories']);
        self::assertSame(['Nameless'], $out[2]->extra['categories']);
    }

    public function testTypeIsNullWhenNoBookOrAudioCategory(): void
    {
        $rows = [[
            'guid'       => 'c6',
            'title'      => 'Movie release',
            'protocol'   => 'torrent',
            'magnetUrl'  => 'magnet:?xt=urn:btih:c6',
            'categories' => [2000, 2040],
        ]];

        self::assertNull(ProwlarrClient::mapResults($rows)[0]->extra['type']);
    }

    public function testBothRangesPreferTheRequestedContentType(): void
    {
        $rows = [[
            'guid'       => 'c7',
            'title'      => 'Cross-posted release',
            'protocol'   => 'torrent',
            'magnetUrl'  => 'magnet:?xt=urn:btih:c7',
            'categories' => [3030, 7020],
        ]];

        self::assertSame(
            ReleaseCandidate::CONTENT_EBOOK,
            ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK)[0]->extra['type'],
        );
        self::assertSame(
            ReleaseCandidate::CONTENT_AUDIOBOOK,
            ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_AUDIOBOOK)[0]->extra['type'],
        );
    }

    public function testBothRangesFallBackToFirstMatchForAnUnknownContentType(): void
    {
        $rows = [[
            'guid'       => 'c8',
            'title'      => 'Cross-posted release',
            'protocol'   => 'torrent',
            'magnetUrl'  => 'magnet:?xt=urn:btih:c8',
            'categories' => [7020, 3030],
        ]];

        self::assertSame('ebook', ProwlarrClient::mapResults($rows, 'comic')[0]->extra['type']);
    }

    public function testMapsPublishDateToADay(): void
    {
        $rows = [
            ['guid' => 'd1', 'title' => 'Dated', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d1', 'publishDate' => '2019-08-01T12:34:56Z'],
            ['guid' => 'd2', 'title' => 'Dated short', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d2', 'publishDate' => '2019-08-01'],
        ];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame('2019-08-01', $out[0]->extra['publishDate']);
        self::assertSame('2019-08-01', $out[1]->extra['publishDate']);
    }

    public function testPublishDateIsNullWhenAbsentOrGarbage(): void
    {
        $rows = [
            ['guid' => 'd3', 'title' => 'No date', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d3'],
            ['guid' => 'd4', 'title' => 'Junk date', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d4', 'publishDate' => 'not a date'],
            ['guid' => 'd5', 'title' => 'Empty date', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d5', 'publishDate' => '  '],
            ['guid' => 'd6', 'title' => 'Array date', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d6', 'publishDate' => ['2019-08-01']],
            ['guid' => 'd7', 'title' => 'Nonsense digits', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:d7', 'publishDate' => '99-99-99T'],
        ];

        foreach (ProwlarrClient::mapResults($rows) as $c) {
            self::assertNull($c->extra['publishDate']);
        }
    }

    /**
     * Subcategory-only filters (the default [3030] scope) silently exclude
     * releases indexers file under the broad parent category, so the search
     * scope is widened with each id's Torznab parent before querying.
     */
    public function testWithParentCategoriesAddsEachIdsParentOnce(): void
    {
        self::assertSame([3030, 3000], ProwlarrClient::withParentCategories([3030]));
        self::assertSame([7000, 7020], ProwlarrClient::withParentCategories([7000, 7020]));
        self::assertSame([3030, 3040, 3000], ProwlarrClient::withParentCategories([3030, 3040]));
        self::assertSame([], ProwlarrClient::withParentCategories([]));
    }

    public function testLeechersAreNullWhenAbsentOrNonNumeric(): void
    {
        $rows = [
            ['guid' => 'a', 'title' => 'No leechers', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:1'],
            ['guid' => 'b', 'title' => 'Bad leechers', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:2', 'leechers' => 'many'],
        ];

        $out = ProwlarrClient::mapResults($rows);

        self::assertCount(2, $out);
        self::assertNull($out[0]->extra['leechers']);
        self::assertNull($out[1]->extra['leechers']);
    }

    public function testMapsIndexerFlagsGivenAsStrings(): void
    {
        $rows = [[
            'guid'         => 'f1',
            'title'        => 'Flagged release',
            'protocol'     => 'torrent',
            'magnetUrl'    => 'magnet:?xt=urn:btih:3',
            'indexerFlags' => ['freeleech', 'internal'],
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame(['freeleech', 'internal'], $out[0]->extra['flags']);
    }

    public function testMapsIndexerFlagsGivenAsObjects(): void
    {
        $rows = [[
            'guid'         => 'f2',
            'title'        => 'Flagged release',
            'protocol'     => 'torrent',
            'magnetUrl'    => 'magnet:?xt=urn:btih:4',
            'indexerFlags' => [['name' => 'Freeleech'], ['name' => 'Internal'], ['id' => 9]],
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame(['freeleech', 'internal'], $out[0]->extra['flags']);
    }

    public function testFlagsAreEmptyWhenAbsentOrMalformed(): void
    {
        $rows = [
            ['guid' => 'f3', 'title' => 'No flags', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:5'],
            ['guid' => 'f4', 'title' => 'Scalar flags', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:6', 'indexerFlags' => 'freeleech'],
        ];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame([], $out[0]->extra['flags']);
        self::assertSame([], $out[1]->extra['flags']);
    }

    public function testFlagsAreNormalizedLowercasedTrimmedAndDeduped(): void
    {
        $rows = [[
            'guid'         => 'f5',
            'title'        => 'Messy flags',
            'protocol'     => 'torrent',
            'magnetUrl'    => 'magnet:?xt=urn:btih:7',
            'indexerFlags' => ['  FreeLeech ', 'internal', 'FREELEECH', '', '   ', ['name' => ' Internal']],
        ]];

        $out = ProwlarrClient::mapResults($rows);

        self::assertSame(['freeleech', 'internal'], $out[0]->extra['flags']);
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

    /**
     * In an audiobook search, a title naming only an ebook format still resolves
     * (fallback set) — raw/unfiltered searches would otherwise show "?" for every
     * off-type row.
     */
    public function testDeriveFormatFallsBackToTheOtherExtensionSet(): void
    {
        $rows = [
            ['guid' => 'x1', 'title' => 'Some Book EPUB retail', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:x1'],
            ['guid' => 'x2', 'title' => 'Pack epub mobi m4b', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:x2'],
        ];

        $audio = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_AUDIOBOOK);
        self::assertSame('epub', $audio[0]->format);
        // The wanted content type's extension set wins when a title names both.
        self::assertSame('m4b', $audio[1]->format);
        self::assertSame('epub', ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK)[1]->format);
    }

    public function testFilterByContentTypeDropsOnlyPositiveMismatches(): void
    {
        $rows = [
            // Audio category → kept in an audiobook search.
            ['guid' => 'k1', 'title' => 'Audio by category', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:k1', 'categories' => [3030]],
            // Ebook category → dropped.
            ['guid' => 'k2', 'title' => 'Ebook by category', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:k2', 'categories' => [7020]],
            // No category, ebook format token → dropped.
            ['guid' => 'k3', 'title' => 'Some Book epub', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:k3'],
            // No category, audio format token → kept.
            ['guid' => 'k4', 'title' => 'Some Book m4b', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:k4'],
            // Nothing classifiable → kept (never silently hidden).
            ['guid' => 'k5', 'title' => 'Bare title', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:k5'],
        ];
        $candidates = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_AUDIOBOOK);

        $kept = ProwlarrClient::filterByContentType($candidates, ReleaseCandidate::CONTENT_AUDIOBOOK);

        self::assertSame(['k1', 'k4', 'k5'], array_map(static fn ($c) => $c->sourceId, $kept));
    }

    public function testFilterByContentTypeKeepsEbooksInAnEbookSearch(): void
    {
        $rows = [
            ['guid' => 'e1', 'title' => 'Ebook by category', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:e1', 'categories' => [7020]],
            ['guid' => 'e2', 'title' => 'Audio by category', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:e2', 'categories' => [3030]],
        ];
        $candidates = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK);

        $kept = ProwlarrClient::filterByContentType($candidates, ReleaseCandidate::CONTENT_EBOOK);

        self::assertSame(['e1'], array_map(static fn ($c) => $c->sourceId, $kept));
    }

    public function testFilterByContentTypeIsANoopForAnUnknownContentType(): void
    {
        $rows = [
            ['guid' => 'n1', 'title' => 'Ebook by category', 'protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:n1', 'categories' => [7020]],
        ];
        $candidates = ProwlarrClient::mapResults($rows, ReleaseCandidate::CONTENT_EBOOK);

        self::assertSame($candidates, ProwlarrClient::filterByContentType($candidates, 'comic'));
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
