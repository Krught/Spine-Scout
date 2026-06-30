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
 * Exercises the audiobook-availability tagging on the trending path: a work with an
 * audio `physical_format` edition (ASIN-only, no ISBN) must be flagged audiobook=true and
 * still survive the ISBN extraction, while a print-only work is audiobook=false. Also asserts
 * the editions projection no longer pre-filters on ISBN (else ASIN-only audiobooks vanish).
 */
final class HardcoverClientAudiobooksTest extends TestCase
{
    public function testTagsAudiobookEditionsAndDropsIsbnEditionFilter(): void
    {
        $bodies = [];
        $json = static fn (array $data): MockResponse => new MockResponse(
            json_encode(['data' => $data], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $responses = [
            // 1) books_trending -> ids in this order
            $json(['books_trending' => ['ids' => [200, 201], 'error' => null]]),
            // 2) hydrate. 200 = audiobook via reading_format_id=2 with NULL physical_format (the
            //    real Hardcover shape — physical_format is almost always null); 201 = print only.
            $json(['books' => [
                ['id' => 200, 'title' => 'Audio Work', 'slug' => 'audio-work',
                 'cached_contributors' => [['author' => ['name' => 'Reader']]], 'cached_image' => null,
                 'editions' => [
                     ['isbn_10' => null, 'isbn_13' => null, 'physical_format' => null, 'edition_format' => 'Audible Audio', 'reading_format_id' => 2, 'users_count' => 9],
                 ]],
                ['id' => 201, 'title' => 'Print Work', 'slug' => 'print-work',
                 'cached_contributors' => [], 'cached_image' => null,
                 'editions' => [
                     ['isbn_10' => null, 'isbn_13' => '9780000000001', 'physical_format' => null, 'edition_format' => 'Hardcover', 'reading_format_id' => 1, 'users_count' => 3],
                     ['isbn_10' => '0000000001', 'isbn_13' => null, 'physical_format' => null, 'edition_format' => 'Paperback', 'reading_format_id' => 1, 'users_count' => 1],
                 ]],
            ]]),
        ];

        $i = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies, &$i, $responses): MockResponse {
            $bodies[] = (string) ($options['body'] ?? '');
            return $responses[$i++];
        });
        $client = new HardcoverClient($http, new ArrayAdapter());

        $out = $client->fetchTrending($this->integration(), 25, 0);

        self::assertCount(2, $out);

        // Order preserved from the ids list.
        self::assertSame('Audio Work', $out[0]->title);
        self::assertTrue($out[0]->audiobook, 'reading_format_id=2 must flag audiobook=true even with null physical_format');
        self::assertSame([], $out[0]->isbns, 'ASIN-only audiobook edition contributes no ISBN');

        self::assertSame('Print Work', $out[1]->title);
        self::assertFalse($out[1]->audiobook, 'print-only work must be audiobook=false');
        self::assertContains('9780000000001', $out[1]->isbns);

        // The hydrate query (2nd call) must not pre-filter editions on ISBN nullness, and must
        // request reading_format_id (the canonical audiobook signal).
        $hydrateBody = $bodies[1];
        self::assertStringNotContainsString('_is_null', $hydrateBody, 'editions must not be ISBN-filtered');
        self::assertStringContainsString('reading_format_id', $hydrateBody);
    }

    private function integration(): Integration
    {
        $integration = new Integration(Integration::KIND_HARDCOVER);
        $integration->setCredentials(['token' => 'test-token']);

        return $integration;
    }
}
