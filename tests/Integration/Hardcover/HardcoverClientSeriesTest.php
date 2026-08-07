<?php

declare(strict_types=1);

namespace App\Tests\Integration\Hardcover;

use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exercises fetchSeriesBooks()'s two-step lookup over canned GraphQL responses (no network):
 * resolve series name → top series id, hydrate books, then the in-PHP mapping — position from
 * *this* series' join row, position-asc sort with nulls last, slug de-dupe — plus the 15-minute
 * cache short-circuit.
 */
final class HardcoverClientSeriesTest extends TestCase
{
    public function testSortsByPositionDedupesAndResolvesName(): void
    {
        $responses = [
            // 1) entity search: top hit (7) is the series used; the namesake (8) is ignored.
            $this->json(['search' => ['ids' => [7, 8]]]),
            // 2) books, in popularity order. "Book C" (pos 2) is most popular; "Book A" carries
            //    no position; "Book B" belongs to another series too (its pos in series 7 is 1);
            //    the fourth row duplicates book-c's slug and must be dropped.
            $this->json(['books' => [
                [
                    'id' => 1, 'title' => 'Book C', 'slug' => 'book-c',
                    'cached_contributors' => [['author' => ['name' => 'Ann Author']]],
                    'cached_image' => ['url' => 'http://covers/c.jpg'],
                    'editions' => [['isbn_13' => '978-0-00-000000-2', 'users_count' => 5, 'reading_format_id' => 2]],
                    'book_series' => [['position' => 2, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
                [
                    'id' => 2, 'title' => 'Book A', 'slug' => 'book-a',
                    'cached_contributors' => [],
                    'cached_image' => null,
                    'editions' => [],
                    'book_series' => [['position' => null, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
                [
                    'id' => 3, 'title' => 'Book B', 'slug' => 'book-b',
                    'cached_contributors' => [],
                    'cached_image' => null,
                    'editions' => [],
                    'book_series' => [
                        ['position' => 9, 'series' => ['id' => 99, 'name' => 'Other Series']],
                        ['position' => 1, 'series' => ['id' => 7, 'name' => 'Test Saga']],
                    ],
                ],
                [
                    'id' => 4, 'title' => 'Book C (duplicate)', 'slug' => 'book-c',
                    'cached_contributors' => [],
                    'cached_image' => null,
                    'editions' => [],
                    'book_series' => [['position' => 4, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
            ]]),
        ];
        $http = new MockHttpClient($responses);
        $client = new HardcoverClient($http, new ArrayAdapter());

        $out = $client->fetchSeriesBooks($this->integration(), 'Test Saga');

        self::assertSame('Test Saga', $out['series']);
        self::assertSame(['book-b', 'book-c', 'book-a'], array_column($out['books'], 'slug'));
        // Position comes from the resolved series' own join row, not the namesake's.
        self::assertSame(1.0, $out['books'][0]['position']);
        self::assertSame(2.0, $out['books'][1]['position']);
        self::assertNull($out['books'][2]['position']);
        // Row mapping: author, cover, normalized ISBNs, audiobook flag.
        $bookC = $out['books'][1];
        self::assertSame('Book C', $bookC['title']);
        self::assertSame('Ann Author', $bookC['author']);
        self::assertSame('http://covers/c.jpg', $bookC['coverUrl']);
        self::assertSame(['9780000000002'], $bookC['isbns']);
        self::assertTrue($bookC['audiobook']);
        self::assertFalse($out['books'][0]['audiobook']);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testEmptyBooksWhenNoSeriesMatches(): void
    {
        $http = new MockHttpClient([$this->json(['search' => ['ids' => []]])]);
        $client = new HardcoverClient($http, new ArrayAdapter());

        $out = $client->fetchSeriesBooks($this->integration(), 'Unknown Saga');

        self::assertSame('Unknown Saga', $out['series']);
        self::assertSame([], $out['books']);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testSecondCallIsServedFromCache(): void
    {
        // Only two responses exist; a third upstream call would exhaust the mock.
        $http = new MockHttpClient([
            $this->json(['search' => ['ids' => [7]]]),
            $this->json(['books' => [[
                'id' => 1, 'title' => 'Solo', 'slug' => 'solo',
                'cached_contributors' => [], 'cached_image' => null, 'editions' => [],
                'book_series' => [['position' => 1, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
            ]]]),
        ]);
        $client = new HardcoverClient($http, new ArrayAdapter());

        $first = $client->fetchSeriesBooks($this->integration(), 'Test Saga');
        // Cache key is the normalized name, so spacing/case differences still hit.
        $second = $client->fetchSeriesBooks($this->integration(), '  test   saga ');

        self::assertSame($first, $second);
        self::assertSame(2, $http->getRequestsCount());
    }

    private function json(array $data): MockResponse
    {
        return new MockResponse(
            json_encode(['data' => $data], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function integration(): Integration
    {
        $integration = new Integration(Integration::KIND_HARDCOVER);
        $integration->setCredentials(['token' => 'test-token']);

        return $integration;
    }
}
