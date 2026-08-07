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
 * listDownloads() enumerates the torrents in the configured category (for the
 * manual request→torrent linking UI). Best-effort per the interface contract:
 * an unconfigured client or a failed login/query yields [] instead of throwing.
 */
final class QbittorrentListDownloadsTest extends TestCase
{
    private const HASH_A = '3601266b0873bfc80fd1f782632b38f9a60bf5a1';
    private const HASH_B = 'aa11266b0873bfc80fd1f782632b38f9a60bf5a1';

    public function testMapsTorrentRowsInTheConfiguredCategory(): void
    {
        $infoUrl = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$infoUrl): MockResponse {
            $infoUrl = $url;

            return new MockResponse(json_encode([
                ['hash' => strtoupper(self::HASH_A), 'name' => 'Red Rising', 'state' => 'downloading', 'progress' => 0.42, 'size' => 1234],
                ['hash' => self::HASH_B, 'name' => '', 'state' => 'stalledUP', 'progress' => 1.0],
                ['name' => 'row without a hash is skipped', 'state' => 'downloading', 'progress' => 0.5],
            ], JSON_THROW_ON_ERROR));
        });

        $list = $this->client($http)->listDownloads();

        self::assertSame([
            [
                'id'        => self::HASH_A,
                'name'      => 'Red Rising',
                'state'     => 'downloading',
                'progress'  => 42.0,
                'sizeBytes' => 1234,
                'completed' => false,
            ],
            [
                'id'        => self::HASH_B,
                'name'      => self::HASH_B, // empty name falls back to the hash
                'state'     => 'stalledUP',
                'progress'  => 100.0,
                'sizeBytes' => null,
                'completed' => true,
            ],
        ], $list);
        self::assertStringContainsString('category=' . TorrentClientConfig::DEFAULT_CATEGORY, (string) $infoUrl);
    }

    public function testReturnsEmptyWhenUnconfigured(): void
    {
        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn(null);

        $client = new QbittorrentDownloadClient(new MockHttpClient(), $repo, new NullLogger());

        self::assertSame([], $client->listDownloads());
    }

    public function testReturnsEmptyWhenLoginFails(): void
    {
        // Bad credentials: qBittorrent answers the login with 200 "Fails.".
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('Fails.'));

        self::assertSame([], $this->client($http, withCredentials: true)->listDownloads());
    }

    // --- helpers ----------------------------------------------------------

    private function client(MockHttpClient $http, bool $withCredentials = false): QbittorrentDownloadClient
    {
        $integration = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            // No username → login skipped (no cookie request).
            ->setCredentials($withCredentials ? ['username' => 'admin', 'password' => 'secret'] : [])
            ->setEnabled(true);

        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn($integration);
        $repo->method('getTorrentClientConfig')->willReturn(TorrentClientConfig::default());

        return new QbittorrentDownloadClient($http, $repo, new NullLogger());
    }
}
