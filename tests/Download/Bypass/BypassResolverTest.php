<?php

declare(strict_types=1);

namespace App\Tests\Download\Bypass;

use App\Download\Bypass\BypasserInterface;
use App\Download\Bypass\BypassResolver;
use App\Download\Progress\DownloadProgressReporter;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BypassResolverTest extends TestCase
{
    public function testModeNoneNeverInvokesBypasser(): void
    {
        $resolver = new BypassResolver([$this->bypasser('external', 'HTML')], $this->settings(DirectDownloadConfig::BYPASS_NONE), new NullLogger());

        self::assertFalse($resolver->isEnabled());
        self::assertNull($resolver->fetch('https://m.test/slow_download/x/0/1'));
    }

    public function testPicksBypasserMatchingConfiguredMode(): void
    {
        $resolver = new BypassResolver(
            [$this->bypasser('decoy', 'DECOY_HTML'), $this->bypasser('external', 'EXTERNAL_HTML')],
            $this->settings(DirectDownloadConfig::BYPASS_EXTERNAL),
            new NullLogger(),
        );

        self::assertTrue($resolver->isEnabled());
        self::assertSame('EXTERNAL_HTML', $resolver->fetch('https://m.test/slow_download/x/0/1'));
    }

    public function testReturnsNullWhenChosenModeHasNoConfiguredBypasser(): void
    {
        // Mode external, but the external bypasser reports unconfigured (no URL).
        $resolver = new BypassResolver(
            [$this->bypasser('external', 'HTML', configured: false)],
            $this->settings(DirectDownloadConfig::BYPASS_EXTERNAL),
            new NullLogger(),
        );

        self::assertFalse($resolver->isEnabled());
        self::assertNull($resolver->fetch('https://m.test/slow_download/x/0/1'));
    }

    private function bypasser(string $mode, string $html, bool $configured = true): BypasserInterface
    {
        return new class($mode, $html, $configured) implements BypasserInterface {
            public function __construct(private string $mode, private string $html, private bool $configured)
            {
            }

            public function mode(): string
            {
                return $this->mode;
            }

            public function isConfigured(DirectDownloadConfig $config): bool
            {
                return $this->configured;
            }

            public function fetch(string $url, DirectDownloadConfig $config, DownloadProgressReporter $progress): ?string
            {
                return $this->html;
            }
        };
    }

    private function settings(string $mode): SearchSettingsProvider
    {
        return new class($mode) implements SearchSettingsProvider {
            public function __construct(private string $mode)
            {
            }

            public function getDirectDownloadConfig(): DirectDownloadConfig
            {
                return new DirectDownloadConfig([], [], bypassMode: $this->mode);
            }

            public function getBestMatchPolicy(): BestMatchPolicy
            {
                return BestMatchPolicy::default();
            }
        };
    }
}
