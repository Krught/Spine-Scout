<?php

declare(strict_types=1);

namespace App\Tests\Integration\Grimmory;

use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryClient;
use App\Support\AudioFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Format derivation over canned /books rows. The regression driving this: BookLore's
 * Komga-compat API returns an API path as `url` (no file extension) and the wildcard
 * MIME "audio/*" for audiobooks, so neither original signal resolved and audiobooks
 * synced with a NULL format — invisible to the format-aware request matcher. The
 * mediaProfile ("AUDIOBOOK"/"EPUB"/"PDF") is the reliable signal there.
 */
final class GrimmoryClientFormatTest extends TestCase
{
    /** @param array<string, mixed> $row */
    #[DataProvider('rows')]
    public function testFormatDerivation(array $row, ?string $expectedFormat): void
    {
        $summary = $this->firstSummary($row);

        self::assertSame($expectedFormat, $summary->format);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: ?string}> */
    public static function rows(): iterable
    {
        yield 'BookLore audiobook: API url + wildcard MIME resolve via mediaProfile' => [
            [
                'id'    => '190',
                'name'  => 'The Odyssey',
                'url'   => '/komga/api/v1/books/190',
                'media' => ['mediaType' => 'audio/*', 'mediaProfile' => 'AUDIOBOOK'],
            ],
            'audiobook',
        ];

        yield 'file-path url extension wins over mediaProfile' => [
            [
                'id'    => '42',
                'name'  => 'Dune',
                'url'   => '/data/audiobooks/Dune/Dune.m4b',
                'media' => ['mediaType' => 'audio/*', 'mediaProfile' => 'AUDIOBOOK'],
            ],
            'm4b',
        ];

        yield 'BookLore epub: mediaProfile resolves EPUB' => [
            [
                'id'    => '196',
                'name'  => '11/22/63',
                'url'   => '/komga/api/v1/books/196',
                'media' => ['mediaType' => 'application/epub+zip', 'mediaProfile' => 'EPUB'],
            ],
            'epub',
        ];

        yield 'no profile: MIME subtype fallback still works' => [
            [
                'id'    => '7',
                'name'  => 'Old Row',
                'url'   => '/komga/api/v1/books/7',
                'media' => ['mediaType' => 'application/epub+zip'],
            ],
            'epub',
        ];

        yield 'wildcard MIME alone stays unresolvable' => [
            [
                'id'    => '8',
                'name'  => 'Mystery Row',
                'url'   => '/komga/api/v1/books/8',
                'media' => ['mediaType' => 'audio/*'],
            ],
            null,
        ];

        yield 'no signals at all' => [
            ['id' => '9', 'name' => 'Bare Row', 'url' => '/komga/api/v1/books/9'],
            null,
        ];
    }

    public function testAudiobookProfileClassifiesAsAudio(): void
    {
        $summary = $this->firstSummary([
            'id'    => '190',
            'name'  => 'The Odyssey',
            'url'   => '/komga/api/v1/books/190',
            'media' => ['mediaType' => 'audio/*', 'mediaProfile' => 'AUDIOBOOK'],
        ]);

        self::assertTrue(AudioFormat::isAudio($summary->format));
    }

    /** @param array<string, mixed> $row */
    private function firstSummary(array $row): \App\Integration\Grimmory\Dto\BookSummary
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['content' => [$row], 'last' => true], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $integration = (new Integration(Integration::KIND_GRIMMORY))
            ->setBaseUrl('http://grimmory:6060/komga')
            ->setCredentials(['username' => 'u', 'password' => 'p']);

        $summaries = iterator_to_array((new GrimmoryClient($http))->listBooks($integration), false);
        self::assertCount(1, $summaries);

        return $summaries[0];
    }
}
