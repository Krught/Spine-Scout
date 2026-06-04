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
 * Exercises fetchSimilarBooks()'s in-PHP co-occurrence tally and the sequel boost over canned
 * GraphQL responses (no network). The four responses correspond to the four calls the method
 * makes in order: resolve seed → curated lists → list members → hydrate top candidates.
 */
final class HardcoverClientSimilarTest extends TestCase
{
    public function testRanksByCoOccurrenceAndBoostsSequels(): void
    {
        $json = static fn (array $data): MockResponse => new MockResponse(
            json_encode(['data' => $data], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $responses = [
            // A) seed slug -> id 100, in series 1
            $json(['books' => [['id' => 100, 'book_series' => [['series' => ['id' => 1]]]]]]),
            // B) curated lists containing the seed
            $json(['list_books' => [['list_id' => 10], ['list_id' => 11]]]),
            // C) members of those lists. Seed (100) must be ignored. Counts: 200->3, 202->3, 201->2.
            $json(['list_books' => [
                ['book_id' => 100], ['book_id' => 200], ['book_id' => 201], ['book_id' => 202],
                ['book_id' => 200], ['book_id' => 201], ['book_id' => 202],
                ['book_id' => 200], ['book_id' => 202],
            ]]),
            // D) hydrate: 201 shares the seed's series (1) -> sequel; 200/202 do not.
            $json(['books' => [
                ['id' => 200, 'title' => 'Alpha', 'slug' => 'alpha', 'cached_contributors' => [['author' => ['name' => 'A']]], 'cached_image' => ['url' => 'http://x/a.jpg'], 'editions' => [], 'book_series' => []],
                ['id' => 201, 'title' => 'Sequel', 'slug' => 'sequel', 'cached_contributors' => [], 'cached_image' => null, 'editions' => [], 'book_series' => [['series' => ['id' => 1]]]],
                ['id' => 202, 'title' => 'Gamma', 'slug' => 'gamma', 'cached_contributors' => [], 'cached_image' => null, 'editions' => [], 'book_series' => [['series' => ['id' => 9]]]],
            ]]),
        ];
        $http = new MockHttpClient($responses);
        $client = new HardcoverClient($http, new ArrayAdapter());

        $out = $client->fetchSimilarBooks($this->integration(), 'red-rising', 60);

        $titles = array_map(static fn ($b) => $b->title, $out);

        // Seed itself is never recommended.
        self::assertNotContains('Red Rising', $titles);
        self::assertCount(3, $out);
        // Sequel (lower raw count) is boosted ahead of the higher-count non-sequels.
        self::assertSame('Sequel', $titles[0]);
        self::assertContains('Alpha', $titles);
        self::assertContains('Gamma', $titles);
        // Exactly four upstream calls regardless of list/member volume.
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testEmptyWhenSeedSlugUnknown(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['books' => []]]), ['response_headers' => ['content-type' => 'application/json']]),
        ]);
        $client = new HardcoverClient($http, new ArrayAdapter());

        self::assertSame([], $client->fetchSimilarBooks($this->integration(), 'does-not-exist'));
        self::assertSame(1, $http->getRequestsCount());
    }

    private function integration(): Integration
    {
        $integration = new Integration(Integration::KIND_HARDCOVER);
        $integration->setCredentials(['token' => 'test-token']);

        return $integration;
    }
}
