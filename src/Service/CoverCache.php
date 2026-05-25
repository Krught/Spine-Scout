<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Integration;
use App\Repository\IntegrationRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Disk-backed cover image cache. The directory at {projectDir}/var/cache/covers
 * is intentionally throwaway: deleting it (or any individual file) is safe and
 * the contents will be re-fetched on demand from the upstream source recorded
 * in each entry's .meta sidecar.
 */
final class CoverCache
{
    private const KIND_REMOTE = 'remote';
    private const KIND_KOMGA  = 'komga';
    private const WEBP_QUALITY = 82;

    public function __construct(
        private readonly string $cacheDir,
        private readonly HttpClientInterface $httpClient,
        private readonly IntegrationRepository $integrations,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Register a remote cover URL and return the proxy URL the browser should
     * request. Writes the sidecar immediately so a later cache miss can refetch.
     */
    public function proxyUrlForRemote(string $sourceUrl): string
    {
        $hash = $this->hashFor(self::KIND_REMOTE, $sourceUrl);
        $this->writeMetaIfMissing($hash, ['kind' => self::KIND_REMOTE, 'url' => $sourceUrl]);
        return $this->urls->generate('cover_proxy', ['hash' => $hash]);
    }

    /**
     * Register a Komga (Grimmory) book by its external id. The proxy fetches
     * with the active Grimmory integration's basic-auth credentials when needed.
     */
    public function proxyUrlForKomga(string $externalId): string
    {
        $hash = $this->hashFor(self::KIND_KOMGA, $externalId);
        $this->writeMetaIfMissing($hash, ['kind' => self::KIND_KOMGA, 'id' => $externalId]);
        return $this->urls->generate('cover_proxy', ['hash' => $hash]);
    }

    /**
     * Pre-warm a remote cover so the first user request hits the cache. Safe to call
     * for already-warm entries (no-op). Returns true if the .webp is on disk after
     * the call, false on fetch/encode failure — callers should treat failure as
     * benign since the proxy will retry on demand.
     */
    public function warmRemote(string $sourceUrl): bool
    {
        return $this->warm(self::KIND_REMOTE, $sourceUrl, ['kind' => self::KIND_REMOTE, 'url' => $sourceUrl]);
    }

    public function warmKomga(string $externalId): bool
    {
        return $this->warm(self::KIND_KOMGA, $externalId, ['kind' => self::KIND_KOMGA, 'id' => $externalId]);
    }

    /**
     * Warm a batch and return a small summary for the caller to log.
     *
     * @param iterable<string> $remoteUrls
     * @param iterable<string> $komgaIds
     * @return array{queued: int, warmed: int, skipped: int, failed: int}
     */
    public function warmAll(iterable $remoteUrls = [], iterable $komgaIds = []): array
    {
        $queued = $warmed = $skipped = $failed = 0;
        foreach ($remoteUrls as $url) {
            if (!is_string($url) || $url === '') { continue; }
            $queued++;
            $hash = $this->hashFor(self::KIND_REMOTE, $url);
            if (is_file($this->imagePath($hash))) { $skipped++; continue; }
            $this->warmRemote($url) ? $warmed++ : $failed++;
        }
        foreach ($komgaIds as $id) {
            if (!is_string($id) || $id === '') { continue; }
            $queued++;
            $hash = $this->hashFor(self::KIND_KOMGA, $id);
            if (is_file($this->imagePath($hash))) { $skipped++; continue; }
            $this->warmKomga($id) ? $warmed++ : $failed++;
        }
        return ['queued' => $queued, 'warmed' => $warmed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param array{kind: string, url?: string, id?: string} $meta */
    private function warm(string $kind, string $key, array $meta): bool
    {
        $hash = $this->hashFor($kind, $key);
        $this->writeMetaIfMissing($hash, $meta);
        $imagePath = $this->imagePath($hash);
        if (is_file($imagePath) && filesize($imagePath) > 0) {
            return true;
        }
        if ($this->fetch($meta, $imagePath)) {
            return true;
        }
        $this->logger->info('Cover warm failed', ['kind' => $kind, 'key' => $key]);
        return false;
    }

    /**
     * Resolve a hash to a ready-to-serve file path + content type. Returns null
     * when the entry is unknown (no sidecar) or the upstream fetch failed.
     *
     * @return array{path: string, contentType: string}|null
     */
    public function resolve(string $hash): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            return null;
        }
        $imagePath = $this->imagePath($hash);
        if (is_file($imagePath) && filesize($imagePath) > 0) {
            return ['path' => $imagePath, 'contentType' => 'image/webp'];
        }
        $meta = $this->readMeta($hash);
        if ($meta === null) {
            return null;
        }
        if (!$this->fetch($meta, $imagePath)) {
            return null;
        }
        return ['path' => $imagePath, 'contentType' => 'image/webp'];
    }

    private function hashFor(string $kind, string $key): string
    {
        return sha1($kind . ':' . $key);
    }

    /** @param array{kind: string, url?: string, id?: string} $meta */
    private function writeMetaIfMissing(string $hash, array $meta): void
    {
        $metaPath = $this->metaPath($hash);
        if (is_file($metaPath)) {
            return;
        }
        $this->ensureDir(\dirname($metaPath));
        file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @return array{kind: string, url?: string, id?: string}|null */
    private function readMeta(string $hash): ?array
    {
        $metaPath = $this->metaPath($hash);
        if (!is_file($metaPath)) {
            return null;
        }
        $raw = file_get_contents($metaPath);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && isset($decoded['kind']) ? $decoded : null;
    }

    /** @param array{kind: string, url?: string, id?: string} $meta */
    private function fetch(array $meta, string $destination): bool
    {
        try {
            [$url, $auth] = $this->resolveUpstream($meta);
            if ($url === null) {
                return false;
            }
            $options = ['timeout' => 30];
            if ($auth !== null) {
                $options['auth_basic'] = $auth;
            }
            $response = $this->httpClient->request('GET', $url, $options);
            if ($response->getStatusCode() !== 200) {
                return false;
            }
            $body = $response->getContent(false);
            if ($body === '') {
                return false;
            }
            $webp = $this->encodeWebp($body);
            if ($webp === null) {
                return false;
            }
            $this->ensureDir(\dirname($destination));
            // Write to a tmp file then rename, so a concurrent reader never sees a partial image.
            $tmp = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $webp) === false) {
                return false;
            }
            return @rename($tmp, $destination);
        } catch (TransportException | HttpExceptionInterface) {
            return false;
        }
    }

    /**
     * @param array{kind: string, url?: string, id?: string} $meta
     * @return array{0: string|null, 1: array{0: string, 1: string}|null}
     */
    private function resolveUpstream(array $meta): array
    {
        if ($meta['kind'] === self::KIND_REMOTE) {
            $url = $meta['url'] ?? null;
            return [is_string($url) && $url !== '' ? $url : null, null];
        }
        if ($meta['kind'] === self::KIND_KOMGA) {
            $id = $meta['id'] ?? null;
            if (!is_string($id) || $id === '') {
                return [null, null];
            }
            $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
            if ($integration === null || !$integration->isEnabled()) {
                return [null, null];
            }
            $base = $integration->getBaseUrl();
            if ($base === null || $base === '') {
                return [null, null];
            }
            $url = rtrim($base, '/') . '/api/v1/books/' . rawurlencode($id) . '/thumbnail';
            $creds = $integration->getCredentials();
            $auth = [(string) ($creds['username'] ?? ''), (string) ($creds['password'] ?? '')];
            return [$url, $auth];
        }
        return [null, null];
    }

    private function imagePath(string $hash): string
    {
        return $this->shard($hash) . '/' . $hash . '.webp';
    }

    /**
     * Decode arbitrary upstream image bytes (JPEG/PNG/GIF/WebP) and re-encode
     * them as WebP. Returns null when GD can't decode the source — caller
     * treats that as a fetch failure so the cover falls back to a placeholder.
     */
    private function encodeWebp(string $bytes): ?string
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        try {
            imagepalettetotruecolor($im);
            imagesavealpha($im, true);
            ob_start();
            $ok = imagewebp($im, null, self::WEBP_QUALITY);
            $out = ob_get_clean();
            return ($ok && is_string($out) && $out !== '') ? $out : null;
        } finally {
            imagedestroy($im);
        }
    }

    private function metaPath(string $hash): string
    {
        return $this->shard($hash) . '/' . $hash . '.meta';
    }

    private function shard(string $hash): string
    {
        return $this->cacheDir . '/' . substr($hash, 0, 2);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

}
