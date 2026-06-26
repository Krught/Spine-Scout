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

    /**
     * Classifies the installed build and decides whether a newer stable release
     * is available, so the sidebar badge can render the right state instead of a
     * naive string compare.
     *
     * The installed version takes one of three shapes:
     *   - "dev" (or anything unrecognised) -> a local/source build; no version
     *     number, never "update available".
     *   - "X.Y.ZN" -> a nightly stamped by CI with the latest release it was
     *     built on top of; "update available" once a stable release > X.Y.Z ships.
     *   - "X.Y.Z" -> a tagged release; "update available" once a higher stable
     *     release exists.
     *
     * @return array{channel: 'dev'|'nightly'|'release', display: ?string, latest: string, updateAvailable: bool}
     */
    public function getStatus(): array
    {
        $installed = $this->installedVersion;
        $latest = $this->getLatestVersion();

        // Nightly: "X.Y.ZN" carries its base release; legacy "nightly" has none.
        if (preg_match('/^(\d+\.\d+\.\d+)N$/', $installed, $m)) {
            return [
                'channel' => 'nightly',
                'display' => $installed,
                'latest' => $latest,
                'updateAvailable' => version_compare($latest, $m[1], '>'),
            ];
        }
        if ('nightly' === $installed) {
            return [
                'channel' => 'nightly',
                'display' => 'nightly',
                'latest' => $latest,
                'updateAvailable' => false,
            ];
        }

        // Release: a plain semver tag.
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $installed, $m)) {
            return [
                'channel' => 'release',
                'display' => $m[1],
                'latest' => $latest,
                'updateAvailable' => version_compare($latest, $m[1], '>'),
            ];
        }

        // Anything else ("dev" or an unrecognised stamp) is a dev build.
        return [
            'channel' => 'dev',
            'display' => null,
            'latest' => $latest,
            'updateAvailable' => false,
        ];
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
