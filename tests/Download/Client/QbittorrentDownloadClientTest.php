<?php

declare(strict_types=1);

namespace App\Tests\Download\Client;

use App\Download\Client\QbittorrentDownloadClient;
use App\Download\Client\TorrentClientSettings;
use App\Entity\Integration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression tests for testConnection(). The production bug: qBittorrent answers a
 * successful POST /api/v2/auth/login with HTTP 204 No Content (not 200), which
 * login() rejected as a failure; and the resulting \RuntimeException escaped
 * testConnection(), 500ing the settings "test connection" endpoint instead of
 * returning the [success, message] tuple the interface promises.
 */
final class QbittorrentDownloadClientTest extends TestCase
{
    public function testConnectionSucceedsWhenLoginReturns204WithSidCookie(): void
    {
        $versionCookie = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$versionCookie): MockResponse {
            if (str_contains($url, '/auth/login')) {
                return new MockResponse('', [
                    'http_code'        => 204,
                    'response_headers' => ['set-cookie' => 'SID=abc123; path=/; HttpOnly'],
                ]);
            }
            if (str_contains($url, '/app/version')) {
                foreach ($options['headers'] ?? [] as $header) {
                    if (stripos((string) $header, 'cookie:') === 0) {
                        $versionCookie = trim(substr((string) $header, 7));
                    }
                }

                return new MockResponse('v5.0.1');
            }

            self::fail('Unexpected request: ' . $method . ' ' . $url);
        });

        [$ok, $message] = $this->client($http)->testConnection();

        self::assertTrue($ok, $message);
        self::assertSame('Connected to download client v5.0.1.', $message);
        self::assertSame('SID=abc123', $versionCookie, 'the SID cookie from the 204 login must be reused');
    }

    public function testConnectionReportsFailureOnBadCredentialsWithoutThrowing(): void
    {
        // qBittorrent rejects bad credentials with 200 and the literal body "Fails."
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('Fails.'));

        [$ok, $message] = $this->client($http)->testConnection();

        self::assertFalse($ok);
        self::assertSame('Download client login failed — check the username and password.', $message);
    }

    public function testConnectionReportsFailureOnTransportErrorWithoutThrowing(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused']));

        [$ok, $message] = $this->client($http)->testConnection();

        self::assertFalse($ok);
        self::assertStringStartsWith('Connection failed:', $message);
    }

    public function testConnectionWithApiKeySkipsLoginAndSendsBearerHeader(): void
    {
        $authHeader = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$authHeader): MockResponse {
            if (str_contains($url, '/auth/login')) {
                self::fail('API-key mode must not call /auth/login (qBittorrent rejects the key there).');
            }
            if (str_contains($url, '/app/version')) {
                foreach ($options['headers'] ?? [] as $header) {
                    if (stripos((string) $header, 'authorization:') === 0) {
                        $authHeader = trim(substr((string) $header, 14));
                    }
                }

                return new MockResponse('v5.2.0');
            }

            self::fail('Unexpected request: ' . $method . ' ' . $url);
        });

        [$ok, $message] = $this->apiKeyClient($http)->testConnection();

        self::assertTrue($ok, $message);
        self::assertSame('Connected to download client v5.2.0.', $message);
        self::assertSame('Bearer qbt_' . str_repeat('a', 28), $authHeader, 'the stored API key must ride the Authorization header');
    }

    public function testConnectionWithApiKeyReportsAuthFailureWithoutThrowing(): void
    {
        foreach ([401, 403] as $status) {
            $http = new MockHttpClient(function (string $method, string $url) use ($status): MockResponse {
                if (str_contains($url, '/auth/login')) {
                    self::fail('API-key mode must not call /auth/login.');
                }

                return new MockResponse('', ['http_code' => $status]);
            });

            [$ok, $message] = $this->apiKeyClient($http)->testConnection();

            self::assertFalse($ok);
            self::assertSame('Download client returned HTTP ' . $status . ' (check credentials).', $message);
        }
    }

    public function testConnectionReportsMissingBaseUrl(): void
    {
        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn(null);

        $client = new QbittorrentDownloadClient(new MockHttpClient(), $repo, new NullLogger());

        [$ok, $message] = $client->testConnection();

        self::assertFalse($ok);
        self::assertSame('Download client URL is not set.', $message);
    }

    // --- helpers ----------------------------------------------------------

    private function client(MockHttpClient $http): QbittorrentDownloadClient
    {
        $integration = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            ->setCredentials(['username' => 'admin', 'password' => 'secret'])
            ->setEnabled(true);

        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn($integration);

        return new QbittorrentDownloadClient($http, $repo, new NullLogger());
    }

    private function apiKeyClient(MockHttpClient $http): QbittorrentDownloadClient
    {
        $integration = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            ->setAuthType(Integration::AUTH_API_KEY)
            ->setCredentials(['api_key' => 'qbt_' . str_repeat('a', 28)])
            ->setEnabled(true);

        $repo = $this->createStub(TorrentClientSettings::class);
        $repo->method('qbittorrentIntegration')->willReturn($integration);

        return new QbittorrentDownloadClient($http, $repo, new NullLogger());
    }
}
