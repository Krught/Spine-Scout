<?php

declare(strict_types=1);

namespace App\Tests\Integration\MyAnonamouse;

use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;

/**
 * In-memory stand-in for the settings provider: hands the client a config and a
 * session cookie, and records the rotated cookie the client persists so the tests
 * can assert on it without a database.
 */
final class FakeMyAnonamouseSettings implements MyAnonamouseSettingsProvider
{
    /** @var list<string> */
    public array $rotations = [];

    /** @var array<string, mixed> */
    public array $accountState = [];

    public function __construct(
        private MyAnonamouseConfig $config,
        public ?string $cookie = 'session-cookie-value',
    ) {
    }

    public function getMyAnonamouseConfig(): MyAnonamouseConfig
    {
        return $this->config;
    }

    public function getMamSessionCookie(): ?string
    {
        return $this->cookie;
    }

    public function persistRotatedMamSessionCookie(string $newValue): void
    {
        $this->rotations[] = $newValue;
        $this->cookie = $newValue;
    }

    public function getMamAccountState(): array
    {
        return $this->accountState;
    }

    public function saveMamAccountState(array $state): void
    {
        $this->accountState = $state;
    }
}
