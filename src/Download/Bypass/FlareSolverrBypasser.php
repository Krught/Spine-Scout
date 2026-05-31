<?php

declare(strict_types=1);

namespace App\Download\Bypass;

use App\Download\Progress\DownloadProgressReporter;
use App\Search\DirectDownload\DirectDownloadConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * External Cloudflare bypasser: delegates to an operator-run FlareSolverr
 * instance (https://github.com/FlareSolverr/FlareSolverr). We POST the target
 * URL to FlareSolverr's `request.get` command; it drives a real browser through
 * the challenge and returns the resolved HTML in `solution.response`.
 *
 * This is the PHP analogue of Shelfmark's bypass/external_bypasser.py. We ship
 * only this thin client — never the solver itself — which keeps to the project
 * stance: the operator brings their own bypass infrastructure and points us at
 * it via Settings → Direct downloads (host:port).
 *
 * Never throws: any transport/parse failure is logged and returns null so the
 * download fails over.
 */
final class FlareSolverrBypasser implements BypasserInterface
{
    private const ENDPOINT_PATH = '/v1';
    private const MAX_TIMEOUT_MS = 60000;          // browser budget handed to FlareSolverr
    private const REQUEST_TIMEOUT = 75.0;          // our HTTP wait = browser budget + buffer

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mode(): string
    {
        return DirectDownloadConfig::BYPASS_EXTERNAL;
    }

    public function isConfigured(DirectDownloadConfig $config): bool
    {
        return $this->baseUrl($config) !== null;
    }

    public function fetch(string $url, DirectDownloadConfig $config, DownloadProgressReporter $progress): ?string
    {
        $base = $this->baseUrl($config);
        if ($base === null) {
            $progress->warn('FlareSolverr address is not configured');

            return null;
        }

        $progress->step('Asking FlareSolverr to clear the protection…');
        try {
            $response = $this->httpClient->request('POST', $base . self::ENDPOINT_PATH, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'cmd'        => 'request.get',
                    'url'        => $url,
                    'maxTimeout' => self::MAX_TIMEOUT_MS,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('FlareSolverr HTTP error', ['url' => $url, 'status' => $response->getStatusCode()]);
                $progress->warn(sprintf('FlareSolverr returned HTTP %d', $response->getStatusCode()));

                return null;
            }

            /** @var array<string, mixed> $result */
            $result = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('FlareSolverr request failed', ['url' => $url, 'error' => $e->getMessage()]);
            $progress->warn('Could not reach FlareSolverr');

            return null;
        }

        if (($result['status'] ?? null) !== 'ok') {
            $this->logger->warning('FlareSolverr did not solve', ['url' => $url, 'status' => $result['status'] ?? 'unknown', 'message' => $result['message'] ?? '']);
            $progress->warn('FlareSolverr could not clear the challenge');

            return null;
        }

        $solution = $result['solution'] ?? null;
        $html = is_array($solution) && is_string($solution['response'] ?? null) ? $solution['response'] : '';
        if (trim($html) === '') {
            $progress->warn('FlareSolverr returned an empty page');

            return null;
        }

        $progress->step('FlareSolverr cleared the protection and loaded the download page');

        return $html;
    }

    /** Normalised FlareSolverr base URL (scheme + host:port, no trailing slash), or null. */
    private function baseUrl(DirectDownloadConfig $config): ?string
    {
        $raw = trim($config->bypassFlaresolverrUrl);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'http://' . $raw;
        }

        return rtrim($raw, '/');
    }
}
