<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Repository\BookRepository;
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
final class CoverCache implements BookCoverProvider
{
    private const KIND_REMOTE = 'remote';
    private const KIND_KOMGA  = 'komga';
    private const WEBP_QUALITY = 82;

    public function __construct(
        private readonly string $cacheDir,
        private readonly HttpClientInterface $httpClient,
        private readonly IntegrationRepository $integrations,
        private readonly UrlGeneratorInterface $urls,
        private readonly BookRepository $books,
        private readonly HardcoverClient $hardcover,
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
            if ($this->imageReady($hash)) { $skipped++; continue; }
            $this->warmRemote($url) ? $warmed++ : $failed++;
        }
        foreach ($komgaIds as $id) {
            if (!is_string($id) || $id === '') { continue; }
            $queued++;
            $hash = $this->hashFor(self::KIND_KOMGA, $id);
            if ($this->imageReady($hash)) { $skipped++; continue; }
            $this->warmKomga($id) ? $warmed++ : $failed++;
        }
        return ['queued' => $queued, 'warmed' => $warmed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Fetch a book's cover in its ORIGINAL raster form (not the webp the proxy
     * serves) for embedding into a downloaded file. Reuses the same remote/Komga
     * upstream resolution as the proxy. Images already in an e-reader-friendly
     * format (JPEG/PNG/GIF) pass through untouched; anything else is transcoded to
     * JPEG. Returns null on any failure — the cover step is optional for callers.
     *
     * @return array{0: string, 1: string}|null [raw image bytes, mime type]
     */
    public function originalCoverForBook(Book $book): ?array
    {
        $meta = $this->metaForBook($book);
        if ($meta === null) {
            return null;
        }
        try {
            [$url, $auth] = $this->resolveUpstream($meta);
            if ($url === null) {
                return null;
            }
            $options = ['timeout' => 30];
            if ($auth !== null) {
                $options['auth_basic'] = $auth;
            }
            $response = $this->httpClient->request('GET', $url, $options);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getContent(false);
            if ($body === '') {
                return null;
            }
        } catch (TransportException | HttpExceptionInterface) {
            return null;
        }

        return $this->toEmbeddableImage($body);
    }

    /** @return array{kind: string, url?: string, id?: string}|null */
    private function metaForBook(Book $book): ?array
    {
        $url = $book->getCoverUrl();
        if (is_string($url) && $url !== '') {
            return ['kind' => self::KIND_REMOTE, 'url' => $url];
        }
        if ($book->getSource() === Book::SOURCE_GRIMMORY && $book->getExternalId() !== '') {
            return ['kind' => self::KIND_KOMGA, 'id' => $book->getExternalId()];
        }
        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private function toEmbeddableImage(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return [$bytes, $mime];
        }
        $jpeg = $this->encodeJpeg($bytes);
        return $jpeg === null ? null : [$jpeg, 'image/jpeg'];
    }

    private function encodeJpeg(string $bytes): ?string
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        try {
            imagepalettetotruecolor($im);
            ob_start();
            $ok = imagejpeg($im, null, 90);
            $out = ob_get_clean();
            return ($ok && is_string($out) && $out !== '') ? $out : null;
        } finally {
            imagedestroy($im);
        }
    }

    /** @param array{kind: string, url?: string, id?: string} $meta */
    private function warm(string $kind, string $key, array $meta): bool
    {
        $hash = $this->hashFor($kind, $key);
        $this->writeMetaIfMissing($hash, $meta);
        if ($this->imageReady($hash)) {
            return true;
        }
        if ($this->fetch($meta, $this->imagePath($hash))) {
            return true;
        }
        $this->logger->info('Cover warm failed', ['kind' => $kind, 'key' => $key]);
        return false;
    }

    /**
     * A cached image is "ready" when it is present, non-empty, and — for Komga covers,
     * whose ids are reassigned by library resets — still belongs to the book that owns
     * its external id. A stale Komga cover is deleted here so callers re-fetch it.
     * Identity is checked against the on-disk sidecar (the source of truth for the
     * `fp` stamp), not any caller-supplied meta.
     */
    private function imageReady(string $hash): bool
    {
        $imagePath = $this->imagePath($hash);
        if (!is_file($imagePath) || filesize($imagePath) <= 0) {
            return false;
        }
        $meta = $this->readMeta($hash);
        if ($meta !== null && ($meta['kind'] ?? null) === self::KIND_KOMGA && !$this->komgaCacheStillValid($meta)) {
            @unlink($imagePath);
            $this->logger->info('Dropped stale Komga cover', ['id' => $meta['id'] ?? null]);
            return false;
        }
        return true;
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
        // imageReady() drops a Komga cover that a library reset has reassigned to a
        // different book, so the chain below re-resolves it to the correct cover.
        if ($this->imageReady($hash)) {
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

    /**
     * True only when the cached Komga cover carries an identity stamp matching the book
     * that currently owns its external id. A missing stamp (legacy entry, pre-dating this
     * check) or a mismatch (id reassigned by a library reset) is treated as stale.
     *
     * @param array{kind: string, url?: string, id?: string, fp?: string} $meta
     */
    private function komgaCacheStillValid(array $meta): bool
    {
        $id = $meta['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }
        $current = $this->komgaIdentity($id);
        $stamped = $meta['fp'] ?? null;
        return is_string($stamped) && $stamped !== '' && $stamped === $current;
    }

    /**
     * Identity fingerprint of the book our sync currently has at this Komga id — the second
     * factor that, together with the Komga id, must match a cached cover for us to trust it.
     * ISBN when known (stable across reindexes), else normalized title+author. The DB is the
     * minute-fresh local mirror of Komga, so comparing against it is the "ISBN + Komga id still
     * agree" check without a per-request upstream call.
     */
    private function komgaIdentity(string $externalId): ?string
    {
        $book = $this->books->findOneBySourceAndExternalId(Book::SOURCE_GRIMMORY, $externalId);
        if ($book === null) {
            return null;
        }
        $isbn = BookRepository::normalizeIsbn($book->getIsbn());
        if ($isbn !== null) {
            return 'isbn:' . $isbn;
        }
        $titleAuthor = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());
        return $titleAuthor !== null ? 'ta:' . $titleAuthor : null;
    }

    /** Re-write a Komga sidecar with the current identity stamp after a fresh fetch. */
    private function stampKomgaMeta(string $externalId): void
    {
        $meta = ['kind' => self::KIND_KOMGA, 'id' => $externalId];
        $fp = $this->komgaIdentity($externalId);
        if ($fp !== null) {
            $meta['fp'] = $fp;
        }
        $hash = $this->hashFor(self::KIND_KOMGA, $externalId);
        $metaPath = $this->metaPath($hash);
        $this->ensureDir(\dirname($metaPath));
        // A failed sidecar write must never break cover serving — the worst case is the
        // entry re-resolves next time rather than serving from a validated cache hit.
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '');
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
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '');
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

    /**
     * Resolve a cover to disk. For Komga (Grimmory) covers the live thumbnail is tried first;
     * on failure we re-pull from Hardcover keyed by the book's ISBN. A genuine miss leaves no
     * file, so the proxy 404s and the UI shows its colour+text placeholder. We never reuse a
     * cover cached under a *different* Komga id — index numbers are reassigned by reindexes/
     * resets, so a same-title cache can belong to an unrelated book.
     *
     * @param array{kind: string, url?: string, id?: string} $meta
     */
    private function fetch(array $meta, string $destination): bool
    {
        $isKomga = ($meta['kind'] ?? null) === self::KIND_KOMGA && !empty($meta['id']) && is_string($meta['id']);

        $ok = $this->fetchLive($meta, $destination);
        if (!$ok && $isKomga) {
            $ok = $this->komgaCoverFallback($meta['id'], $destination);
        }
        if ($ok && $isKomga) {
            // Stamp the entry with the book's current identity so a later reindex that reassigns
            // this id is detected (stamp no longer matches) and the now-wrong cover is dropped.
            $this->stampKomgaMeta($meta['id']);
        }
        return $ok;
    }

    /**
     * Komga cover fallback: re-pull from Hardcover by the book's ISBN. The image is fetched
     * for this exact book, so the cover is attributable — unlike reusing a file cached under
     * some other (possibly reassigned) Komga id.
     */
    private function komgaCoverFallback(string $externalId, string $destination): bool
    {
        $book = $this->books->findOneBySourceAndExternalId(Book::SOURCE_GRIMMORY, $externalId);
        if ($book === null) {
            return false;
        }
        $isbn = $book->getIsbn();
        return $isbn !== null && $this->fetchCoverFromHardcover($isbn, $destination);
    }

    /** Re-pull a cover from Hardcover keyed by ISBN and cache it under $destination. */
    private function fetchCoverFromHardcover(string $isbn, string $destination): bool
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled()) {
            return false;
        }
        try {
            $url = $this->hardcover->fetchCoverUrlByIsbn($integration, $isbn);
        } catch (HardcoverException) {
            return false;
        }
        if ($url === null) {
            return false;
        }
        if ($this->fetchLive(['kind' => self::KIND_REMOTE, 'url' => $url], $destination)) {
            $this->logger->info('Cover re-pulled from Hardcover by ISBN', ['isbn' => $isbn]);
            return true;
        }
        return false;
    }

    /** @param array{kind: string, url?: string, id?: string} $meta */
    private function fetchLive(array $meta, string $destination): bool
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
