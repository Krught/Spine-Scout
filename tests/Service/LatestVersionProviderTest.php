<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LatestVersionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LatestVersionProviderTest extends TestCase
{
    public function testDevBuildHasNoNumberAndNeverOffersUpdate(): void
    {
        $status = $this->provider('dev', ['v0.0.9'])->getStatus();

        self::assertSame('dev', $status['channel']);
        self::assertNull($status['display']);
        self::assertFalse($status['updateAvailable']);
    }

    public function testUnrecognisedStampIsTreatedAsDev(): void
    {
        self::assertSame('dev', $this->provider('sha-abc1234', ['v0.0.9'])->getStatus()['channel']);
    }

    public function testNightlyUpToDateWhenBaseEqualsLatest(): void
    {
        $status = $this->provider('0.0.7N', ['v0.0.7'])->getStatus();

        self::assertSame('nightly', $status['channel']);
        self::assertSame('0.0.7N', $status['display']);
        self::assertFalse($status['updateAvailable']);
    }

    public function testNightlyOffersUpdateWhenNewerReleaseExists(): void
    {
        $status = $this->provider('0.0.7N', ['v0.0.8', 'v0.0.7'])->getStatus();

        self::assertSame('nightly', $status['channel']);
        self::assertTrue($status['updateAvailable']);
    }

    public function testLegacyNightlyNeverOffersUpdate(): void
    {
        $status = $this->provider('nightly', ['v0.0.9'])->getStatus();

        self::assertSame('nightly', $status['channel']);
        self::assertSame('nightly', $status['display']);
        self::assertFalse($status['updateAvailable']);
    }

    public function testReleaseUpToDate(): void
    {
        $status = $this->provider('0.0.6', ['v0.0.6'])->getStatus();

        self::assertSame('release', $status['channel']);
        self::assertSame('0.0.6', $status['display']);
        self::assertFalse($status['updateAvailable']);
    }

    public function testReleaseOffersUpdateWhenHigherReleaseExists(): void
    {
        $status = $this->provider('0.0.6', ['v0.0.7', 'v0.0.6'])->getStatus();

        self::assertSame('release', $status['channel']);
        self::assertTrue($status['updateAvailable']);
        self::assertSame('0.0.7', $status['latest']);
    }

    /**
     * @param list<string> $tagNames
     */
    private function provider(string $installed, array $tagNames): LatestVersionProvider
    {
        $payload = json_encode(array_map(static fn (string $name): array => ['name' => $name], $tagNames), \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(new MockResponse($payload, ['response_headers' => ['content-type' => 'application/json']]));

        return new LatestVersionProvider($client, new ArrayAdapter(), $installed, 'Krught/Spine-Scout');
    }
}
