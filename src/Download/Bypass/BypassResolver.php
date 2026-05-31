<?php

declare(strict_types=1);

namespace App\Download\Bypass;

use App\Download\Progress\DownloadProgressReporter;
use App\Download\Progress\NullDownloadProgressReporter;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the active Cloudflare bypasser from the operator's DirectDownloadConfig
 * (mode none/internal/external) and runs it. Returns the resolved page HTML, or
 * null when bypass is disabled, the chosen mode is unconfigured, or the
 * bypasser couldn't resolve the page. Never throws — callers treat null as
 * "couldn't get past it" and fail over.
 */
final class BypassResolver
{
    /**
     * @param iterable<BypasserInterface> $bypassers
     */
    public function __construct(
        #[AutowireIterator('app.download_bypasser')]
        private readonly iterable $bypassers,
        private readonly SearchSettingsProvider $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether any bypass is active (mode != none and the chosen mode is configured). */
    public function isEnabled(): bool
    {
        $config = $this->settings->getDirectDownloadConfig();

        return $this->pick($config) !== null;
    }

    /**
     * Resolve $url through the configured bypasser. Returns HTML or null.
     */
    public function fetch(string $url, ?DownloadProgressReporter $progress = null): ?string
    {
        $progress ??= new NullDownloadProgressReporter();
        $config = $this->settings->getDirectDownloadConfig();
        $bypasser = $this->pick($config);
        if ($bypasser === null) {
            return null;
        }

        try {
            return $bypasser->fetch($url, $config, $progress);
        } catch (\Throwable $e) {
            // Defensive: the contract says bypassers don't throw, but a bug
            // here must still fail over rather than abort the download job.
            $this->logger->warning('Bypasser threw', ['mode' => $config->bypassMode, 'url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function pick(DirectDownloadConfig $config): ?BypasserInterface
    {
        if ($config->bypassMode === DirectDownloadConfig::BYPASS_NONE) {
            return null;
        }
        foreach ($this->bypassers as $bypasser) {
            if ($bypasser->mode() === $config->bypassMode && $bypasser->isConfigured($config)) {
                return $bypasser;
            }
        }

        return null;
    }
}
