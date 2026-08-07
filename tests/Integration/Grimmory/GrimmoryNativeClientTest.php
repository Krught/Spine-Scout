<?php

declare(strict_types=1);

namespace App\Tests\Integration\Grimmory;

use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryException;
use App\Integration\Grimmory\GrimmoryNativeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exercises the native (JWT) sidecar-import client over canned responses:
 * login token handling, `/komga` base-URL stripping, per-library failure
 * isolation, and the isConfigured() gate on options['native'].
 */
final class GrimmoryNativeClientTest extends TestCase
{
    public function testLoginThenImportsEveryLibraryAndSumsCounts(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if (str_contains($url, '/auth/login')) {
                return self::json(['accessToken' => 'jwt-123']);
            }
            if (str_contains($url, '/libraries/1/')) {
                return self::json(['message' => 'ok', 'imported' => 3]);
            }

            return self::json(['message' => 'ok', 'imported' => 4]);
        });

        $client = new GrimmoryNativeClient($http);
        $result = $client->importAllSidecars($this->integration('http://grimmory:6060'));

        self::assertSame(['libraries' => 2, 'imported' => 7], $result);
        self::assertCount(3, $requests);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://grimmory:6060/api/v1/auth/login', $requests[0]['url']);
        self::assertJsonStringEqualsJsonString(
            '{"username":"native-user","password":"native-pass"}',
            $requests[0]['options']['body'],
        );

        self::assertSame('http://grimmory:6060/api/v1/libraries/1/sidecar/import-all', $requests[1]['url']);
        self::assertSame('http://grimmory:6060/api/v1/libraries/2/sidecar/import-all', $requests[2]['url']);
        foreach ([1, 2] as $i) {
            self::assertSame('POST', $requests[$i]['method']);
            self::assertContains('Authorization: Bearer jwt-123', $requests[$i]['options']['headers']);
        }
    }

    public function testStripsTrailingKomgaSegmentFromBaseUrl(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;
            if (str_contains($url, '/auth/login')) {
                return self::json(['token' => 'fallback-jwt']); // exercise the "token" fallback key too
            }

            return self::json(['message' => 'ok', 'imported' => 1]);
        });

        $client = new GrimmoryNativeClient($http);
        $integration = $this->integration('http://grimmory:6060/komga', libraries: [['id' => '1', 'name' => 'Audiobooks']]);
        $result = $client->importAllSidecars($integration);

        self::assertSame(['libraries' => 1, 'imported' => 1], $result);
        self::assertSame([
            'http://grimmory:6060/api/v1/auth/login',
            'http://grimmory:6060/api/v1/libraries/1/sidecar/import-all',
        ], $urls);
    }

    public function testHonorsSelectedLibrarySubset(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;
            if (str_contains($url, '/auth/login')) {
                return self::json(['accessToken' => 'jwt']);
            }

            return self::json(['message' => 'ok', 'imported' => 2]);
        });

        $client = new GrimmoryNativeClient($http);
        $integration = $this->integration('http://grimmory:6060');
        $integration->setSelectedLibraries(['2']);
        $result = $client->importAllSidecars($integration);

        self::assertSame(['libraries' => 1, 'imported' => 2], $result);
        self::assertSame([
            'http://grimmory:6060/api/v1/auth/login',
            'http://grimmory:6060/api/v1/libraries/2/sidecar/import-all',
        ], $urls);
    }

    public function testLoginRejectionMentionsNativeCredentials(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 401])]);
        $client = new GrimmoryNativeClient($http);

        try {
            $client->importAllSidecars($this->integration('http://grimmory:6060'));
            self::fail('Expected GrimmoryException for a 401 login.');
        } catch (GrimmoryException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Komga/OPDS credentials', $e->getMessage());
        }
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testOneLibraryFailingDoesNotAbortTheRest(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/auth/login')) {
                return self::json(['accessToken' => 'jwt']);
            }
            if (str_contains($url, '/libraries/1/')) {
                return new MockResponse('boom', ['http_code' => 500]);
            }

            return self::json(['message' => 'ok', 'imported' => 5]);
        });

        $client = new GrimmoryNativeClient($http);
        $result = $client->importAllSidecars($this->integration('http://grimmory:6060'));

        self::assertSame(['libraries' => 1, 'imported' => 5], $result);
        self::assertSame(3, $http->getRequestsCount()); // login + both libraries attempted
    }

    public function testThrowsWhenEveryLibraryFails(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/auth/login')) {
                return self::json(['accessToken' => 'jwt']);
            }

            return new MockResponse('boom', ['http_code' => 500]);
        });

        $client = new GrimmoryNativeClient($http);

        $this->expectException(GrimmoryException::class);
        $this->expectExceptionMessageMatches('/failed for every library/');
        $client->importAllSidecars($this->integration('http://grimmory:6060'));
    }

    /** @param array<string, mixed>|null $native */
    #[DataProvider('isConfiguredCases')]
    public function testIsConfigured(?string $baseUrl, ?array $native, bool $expected): void
    {
        $integration = new Integration(Integration::KIND_GRIMMORY);
        $integration->setBaseUrl($baseUrl);
        if ($native !== null) {
            $integration->setOptions(['native' => $native]);
        }

        $client = new GrimmoryNativeClient(new MockHttpClient());
        self::assertSame($expected, $client->isConfigured($integration));
    }

    /** @return iterable<string, array{0: ?string, 1: ?array<string, mixed>, 2: bool}> */
    public static function isConfiguredCases(): iterable
    {
        $full = ['username' => 'u', 'password' => 'p', 'sidecarImport' => true];

        yield 'fully configured'        => ['http://grimmory:6060', $full, true];
        yield 'no base url'             => [null, $full, false];
        yield 'blank base url'          => ['', $full, false];
        yield 'no native options'       => ['http://grimmory:6060', null, false];
        yield 'toggle off'              => ['http://grimmory:6060', ['username' => 'u', 'password' => 'p', 'sidecarImport' => false], false];
        yield 'toggle truthy-not-true'  => ['http://grimmory:6060', ['username' => 'u', 'password' => 'p', 'sidecarImport' => 1], false];
        yield 'toggle missing'          => ['http://grimmory:6060', ['username' => 'u', 'password' => 'p'], false];
        yield 'blank username'          => ['http://grimmory:6060', ['username' => '  ', 'password' => 'p', 'sidecarImport' => true], false];
        yield 'missing username'        => ['http://grimmory:6060', ['password' => 'p', 'sidecarImport' => true], false];
        yield 'blank password'          => ['http://grimmory:6060', ['username' => 'u', 'password' => '', 'sidecarImport' => true], false];
    }

    /**
     * @param list<array{id: string, name: string}>|null $libraries
     */
    private function integration(string $baseUrl, ?array $libraries = null): Integration
    {
        $integration = new Integration(Integration::KIND_GRIMMORY);
        $integration->setBaseUrl($baseUrl);
        $integration->setOptions(['native' => [
            'username' => 'native-user',
            'password' => 'native-pass',
            'sidecarImport' => true,
        ]]);
        $integration->setDiscoveredLibraries($libraries ?? [
            ['id' => '1', 'name' => 'Audiobooks'],
            ['id' => '2', 'name' => 'Ebooks'],
        ]);

        return $integration;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
