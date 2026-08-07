<?php

declare(strict_types=1);

namespace App\Tests\Download\Client;

use App\Download\Client\QbittorrentDownloadClient;
use App\Download\Client\TorrentClientSettings;
use App\Download\Torrent\TorrentClientConfig;
use App\Entity\Integration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression tests for addDownload hash resolution. The production bug: a non-magnet
 * add (a Prowlarr /download proxy URL) was resolved with a 2s before/after category
 * diff that timed out before the proxied torrent registered, so the job was marked
 * error while qBittorrent kept downloading it untracked. The tag-based resolution
 * fixes that — and must not orphan a torrent when resolution genuinely fails.
 */
final class QbittorrentAddDownloadTest extends TestCase
{
    private const HEX_HASH = '3601266b0873bfc80fd1f782632b38f9a60bf5a1';

    public function testMagnetReturnsHashImmediatelyWithoutTaggingOrPolling(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse('Ok.');
        });

        $hash = $this->client($http)->addDownload(
            'magnet:?xt=urn:btih:' . strtoupper(self::HEX_HASH) . '&dn=Red+Rising',
            'Red Rising',
        );

        self::assertSame(self::HEX_HASH, $hash);
        // Exactly one call (the add). No createTags, no torrents/info polling.
        self::assertCount(1, $requests);
        self::assertStringContainsString('/api/v2/torrents/add', $requests[0]);
    }

    public function testProxyUrlResolvesViaUniqueTag(): void
    {
        $tag = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$tag): MockResponse {
            if (str_contains($url, '/torrents/add')) {
                parse_str((string) ($options['body'] ?? ''), $body);
                $tag = (string) ($body['tags'] ?? '');

                return new MockResponse('Ok.');
            }
            if (str_contains($url, '/torrents/info')) {
                // The torrent we tagged has now registered in the client.
                return new MockResponse(json_encode([
                    ['hash' => strtoupper(self::HEX_HASH), 'tags' => (string) $tag, 'state' => 'metaDL'],
                ], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('Ok.'); // createTags / deleteTags
        });

        $hash = $this->client($http)->addDownload($this->proxyUrl(), 'Piranesi');

        self::assertSame(self::HEX_HASH, $hash);
        self::assertNotNull($tag);
        self::assertStringStartsWith('spinescout-add-', (string) $tag);
    }

    public function testUnresolvableAddThrowsAndDoesNotOrphan(): void
    {
        $deletedTags = false;
        $http = new MockHttpClient(function (string $method, string $url) use (&$deletedTags): MockResponse {
            if (str_contains($url, '/torrents/deleteTags')) {
                $deletedTags = true;
            }

            // Add succeeds; the torrent never appears in torrents/info.
            return str_contains($url, '/torrents/info')
                ? new MockResponse('[]')
                : new MockResponse('Ok.');
        });

        $this->expectException(\RuntimeException::class);

        try {
            $this->client($http, attempts: 2)->addDownload($this->proxyUrl(), 'Fourth Wing');
        } finally {
            self::assertTrue($deletedTags, 'the throwaway tag must be cleaned up');
        }
    }

    public function testUnresolvedTorrentThatLaterAppearsIsDeletedWithFiles(): void
    {
        $tag = null;
        $infoCalls = 0;
        $deleteBody = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$tag, &$infoCalls, &$deleteBody): MockResponse {
            if (str_contains($url, '/torrents/add')) {
                parse_str((string) ($options['body'] ?? ''), $body);
                $tag = (string) ($body['tags'] ?? '');

                return new MockResponse('Ok.');
            }
            if (str_contains($url, '/torrents/delete') && !str_contains($url, 'deleteTags')) {
                $deleteBody = (string) ($options['body'] ?? '');

                return new MockResponse('Ok.');
            }
            if (str_contains($url, '/torrents/info')) {
                ++$infoCalls;
                // Empty during the two poll attempts; the torrent only surfaces at
                // cleanup time (third info call), simulating a very slow register.
                if ($infoCalls <= 2) {
                    return new MockResponse('[]');
                }

                return new MockResponse(json_encode([
                    ['hash' => strtoupper(self::HEX_HASH), 'tags' => (string) $tag],
                ], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('Ok.');
        });

        try {
            $this->client($http, attempts: 2)->addDownload($this->proxyUrl(), 'The Midnight Library');
            self::fail('expected resolution to fail');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNotNull($deleteBody, 'a stuck torrent surfacing at cleanup must be deleted');
        parse_str((string) $deleteBody, $parsed);
        self::assertSame('true', $parsed['deleteFiles'] ?? null);
        self::assertSame(self::HEX_HASH, $parsed['hashes'] ?? null);
    }

    public function testPendingJsonAdd202ResolvesViaTagPolling(): void
    {
        // WebAPI 2.14+ (qBittorrent ≥ 5.2) answers a URL add it must fetch
        // asynchronously with 202 and pending JSON. Production regression: the
        // old code rejected this both on the non-200 status AND on the "fail"
        // substring inside "failure_count", erroring a successful add.
        $tag = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$tag): MockResponse {
            if (str_contains($url, '/torrents/add')) {
                parse_str((string) ($options['body'] ?? ''), $body);
                $tag = (string) ($body['tags'] ?? '');

                return new MockResponse(
                    '{"success_count":0,"failure_count":0,"pending_count":1,"added_torrent_ids":[]}',
                    ['http_code' => 202],
                );
            }
            if (str_contains($url, '/torrents/info')) {
                return new MockResponse(json_encode([
                    ['hash' => strtoupper(self::HEX_HASH), 'tags' => (string) $tag, 'state' => 'metaDL'],
                ], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('Ok.'); // createTags / deleteTags
        });

        $hash = $this->client($http)->addDownload($this->proxyUrl(), 'Piranesi');

        self::assertSame(self::HEX_HASH, $hash);
    }

    public function testJsonAddedTorrentIdsReturnsHashWithoutPolling(): void
    {
        $polled = false;
        $deletedTag = false;
        $http = new MockHttpClient(function (string $method, string $url) use (&$polled, &$deletedTag): MockResponse {
            if (str_contains($url, '/torrents/info')) {
                $polled = true;
            }
            if (str_contains($url, '/torrents/deleteTags')) {
                $deletedTag = true;
            }
            if (str_contains($url, '/torrents/add')) {
                return new MockResponse(json_encode([
                    'success_count'     => 1,
                    'failure_count'     => 0,
                    'pending_count'     => 0,
                    'added_torrent_ids' => [strtoupper(self::HEX_HASH)],
                ], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('Ok.'); // createTags
        });

        $hash = $this->client($http)->addDownload($this->proxyUrl(), 'Legends & Lattes');

        self::assertSame(self::HEX_HASH, $hash);
        self::assertFalse($polled, 'the id from the JSON response makes tag polling unnecessary');
        self::assertTrue($deletedTag, 'the throwaway tag must be cleaned up');
    }

    public function testLegacyFailsBodyThrows(): void
    {
        // WebAPI < 2.14 rejects an add with 200 and the literal body "Fails.".
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('Fails.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the torrent add');

        $this->client($http)->addDownload('magnet:?xt=urn:btih:' . self::HEX_HASH, 'Dune');
    }

    public function testConflict409OnMagnetReturnsKnownHash(): void
    {
        // 409 means the torrent is already in the client (or the add failed); a
        // magnet carries its hash, so re-link to the existing torrent.
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse('', ['http_code' => 409]);
        });

        $hash = $this->client($http)->addDownload('magnet:?xt=urn:btih:' . strtoupper(self::HEX_HASH), 'Red Rising');

        self::assertSame(self::HEX_HASH, $hash);
        self::assertCount(1, $requests);
    }

    public function testConflict409OnUrlAddThrows(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            return str_contains($url, '/torrents/add')
                ? new MockResponse('', ['http_code' => 409])
                : new MockResponse('Ok.'); // createTags / deleteTags
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already added or could not be added');

        $this->client($http)->addDownload($this->proxyUrl(), 'Fourth Wing');
    }

    // --- helpers ----------------------------------------------------------

    private function client(MockHttpClient $http, int $attempts = 30): QbittorrentDownloadClient
    {
        $integration = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            ->setCredentials([]) // no username → login skipped (no cookie request)
            ->setEnabled(true);

        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn($integration);
        $repo->method('getTorrentClientConfig')->willReturn(TorrentClientConfig::default());

        // 1µs interval keeps the failure-path tests fast.
        return new QbittorrentDownloadClient($http, $repo, new NullLogger(), $attempts, 1);
    }

    private function proxyUrl(): string
    {
        return 'http://192.168.0.37:9696/17/download?apikey=secret&link=TVpJc3BBNGJhcUhRVVFWVQ';
    }
}
