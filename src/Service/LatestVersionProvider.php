<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the latest published Spine Scout version by reading the project's git
 * tags from the GitHub API and picking the highest stable semver (vX.Y.Z). This
 * mirrors how the release workflow tags `:latest`, so the sidebar "update
 * available" badge reflects real releases instead of a hard-coded value.
 *
 * The result is cached (GitHub allows only 60 unauthenticated requests/hour) and
 * every failure path falls back to the installed version, so a network hiccup or
 * rate-limit never produces a false "update available".
 */
final class LatestVersionProvider
{
    private const TAGS_ENDPOINT = 'https://api.github.com/repos/%s/tags?per_page=100';
    private const CACHE_KEY = 'spinescout.latest_version';
    private const TTL_OK = 21600;      // 6h on success
    private const TTL_FALLBACK = 1800; // 30m when we had to fall back

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $installedVersion,
        private readonly string $repository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getLatestVersion(): string
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): string {
            try {
                $response = $this->httpClient->request('GET', sprintf(self::TAGS_ENDPOINT, $this->repository), [
                    'headers' => [
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                        'User-Agent' => 'SpineScout',
                    ],
                    'timeout' => 5,
                ]);

                $latest = $this->highestStableVersion($response->toArray(false));

                if (null === $latest) {
                    $item->expiresAfter(self::TTL_FALLBACK);

                    return $this->installedVersion;
                }

                $item->expiresAfter(self::TTL_OK);

                return $latest;
            } catch (\Throwable $e) {
                $this->logger?->warning('Could not fetch latest Spine Scout version from GitHub.', ['exception' => $e]);
                $item->expiresAfter(self::TTL_FALLBACK);

                return $this->installedVersion;
            }
        });
    }

    /**
     * @param array<int, array{name?: string}> $tags
     */
    private function highestStableVersion(array $tags): ?string
    {
        $versions = [];
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? '';
            // Stable releases only (vX.Y.Z); skip pre-releases like v1.2.0-rc1.
            if (\is_string($name) && preg_match('/^v?(\d+\.\d+\.\d+)$/', $name, $m)) {
                $versions[] = $m[1];
            }
        }

        if ([] === $versions) {
            return null;
        }

        usort($versions, 'version_compare');

        return end($versions);
    }
}
