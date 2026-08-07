<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryClient;
use App\Integration\Grimmory\GrimmoryException;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Persists upstream lookup results so subsequent clicks on the same book are served from the DB.
 */
final class BookMetadataService
{
    private const COVER_CACHE_TTL = 60 * 60 * 24 * 30;

    /**
     * How long a "this book has no cover" answer is remembered. Short relative to
     * {@see self::COVER_CACHE_TTL} because it's a negative result we do want to retry
     * eventually — but long enough that a coverless book costs zero upstream calls
     * across a browsing session instead of one per page render.
     */
    private const COVER_MISS_TTL = 60 * 60 * 6;

    /** Cache payload marking a known-coverless book. Distinct from "no cache entry". */
    private const COVER_MISS = false;

    /**
     * Upper bound on synchronous upstream cover lookups per request cycle. The service is
     * instantiated once per PHP request, so the counter below is naturally per-page-render:
     * a 50-row page can spend at most this many blocking Hardcover/OpenLibrary calls, and
     * the rows beyond it render the placeholder and fill in on subsequent loads (each of
     * which gets a fresh budget, so the page converges).
     */
    private const MAX_REMOTE_COVER_FETCHES = 3;

    /** Remote cover lookups already spent in this request cycle. @see self::MAX_REMOTE_COVER_FETCHES */
    private int $remoteCoverFetches = 0;

    /**
     * Cooldown before a second on-open audiobook backfill attempt. The trending poll caches
     * availability but not narrator/runtime, so the first modal open fetches them. When Hardcover
     * genuinely has no narrator/runtime for an audio edition, this stops every subsequent open
     * from re-hitting upstream while still retrying periodically in case the data lands later.
     */
    private const AUDIO_BACKFILL_COOLDOWN = '-7 days';

    public function __construct(
        private readonly BookRepository $books,
        private readonly IntegrationRepository $integrations,
        private readonly EntityManagerInterface $em,
        private readonly GrimmoryClient $grimmory,
        private readonly HardcoverClient $hardcover,
        private readonly OpenLibraryClient $openLibrary,
        private readonly CoverCache $covers,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Returns a cover-proxy URL for the given book, fetching from upstream only as a
     * last resort. Resolution order, cheapest first:
     *
     * 1. Grimmory books are derived deterministically from the external id — no I/O.
     * 2. The PSR cache, keyed by book id. A hit is either a proxy URL or the
     *    {@see self::COVER_MISS} sentinel meaning "known coverless, don't ask again yet".
     * 3. The persisted {@see Book::$coverUrl} column — the sync/metadata refresh already
     *    wrote it, so a DB-known cover never costs an HTTP call. The result primes the cache.
     * 4. A synchronous upstream lookup, capped at {@see self::MAX_REMOTE_COVER_FETCHES} per
     *    request cycle. Rows past the cap return null (placeholder) and are left uncached so
     *    the next page load retries them; a genuine "upstream has no cover" answer is cached
     *    negatively for {@see self::COVER_MISS_TTL}.
     */
    public function ensureCoverProxyUrl(Book $book): ?string
    {
        if ($book->getSource() === Book::SOURCE_GRIMMORY) {
            return $this->covers->proxyUrlForKomga($book->getExternalId());
        }

        $bookId = $book->getId();
        if ($bookId === null) {
            return null;
        }

        $cacheKey = 'book.cover.' . $bookId;
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $value = $item->get();
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if ($value === self::COVER_MISS) {
                return null;
            }
        }

        // The DB already knows this book's cover in the common case — use it before
        // considering any upstream call, and prime the cache so the next render skips
        // even the entity load path.
        $storedUrl = $book->getCoverUrl();
        if (is_string($storedUrl) && $storedUrl !== '') {
            return $this->rememberCover($item, $this->covers->proxyUrlForRemote($storedUrl));
        }

        if ($this->remoteCoverFetches >= self::MAX_REMOTE_COVER_FETCHES) {
            return null;
        }
        ++$this->remoteCoverFetches;

        $remoteUrl = $this->fetchRemoteCoverUrl($book);
        if ($remoteUrl === null) {
            $item->set(self::COVER_MISS);
            $item->expiresAfter(self::COVER_MISS_TTL);
            $this->cache->save($item);
            return null;
        }

        return $this->rememberCover($item, $this->covers->proxyUrlForRemote($remoteUrl));
    }

    /** Store a resolved proxy URL in the given (already fetched) cache item and return it. */
    private function rememberCover(\Psr\Cache\CacheItemInterface $item, string $proxyUrl): string
    {
        $item->set($proxyUrl);
        $item->expiresAfter(self::COVER_CACHE_TTL);
        $this->cache->save($item);

        return $proxyUrl;
    }

    private function fetchRemoteCoverUrl(Book $book): ?string
    {
        try {
            $data = match ($book->getSource()) {
                Book::SOURCE_HARDCOVER   => $this->fetchFromHardcover($book->getExternalId()),
                Book::SOURCE_OPENLIBRARY => $this->openLibrary->fetchBookMetadataByKey($book->getExternalId()),
                default => null,
            };
        } catch (GrimmoryException | HardcoverException | OpenLibraryException) {
            return null;
        }
        if (!is_array($data) || empty($data['coverUrl'])) {
            return null;
        }
        return (string) $data['coverUrl'];
    }

    /**
     * Seed fields are used when creating a new row so the popup shows something
     * while the upstream lookup runs.
     *
     * @param array{title?: ?string, author?: ?string, coverUrl?: ?string, externalUrl?: ?string} $seed
     */
    public function loadBySourceAndExternalId(string $source, string $externalId, array $seed = []): Book
    {
        $book = $this->books->findOneBySourceAndExternalId($source, $externalId);
        if ($book === null) {
            $book = new Book($source, $externalId, $seed['title'] ?? '(untitled)');
            $book->setDownloaded(false);
            if (!empty($seed['author'])) {
                $book->setAuthor((string) $seed['author']);
            }
            if (!empty($seed['externalUrl'])) {
                $book->setExternalUrl((string) $seed['externalUrl']);
            }
            $this->em->persist($book);
        }
        if ($book->getMetadataFetchedAt() === null) {
            $this->refresh($book);
        }
        $this->em->flush();
        return $book;
    }

    public function loadByInternalId(int $id): ?Book
    {
        $book = $this->books->find($id);
        if ($book === null) {
            return null;
        }
        if ($book->getMetadataFetchedAt() === null) {
            $this->refresh($book);
            $this->em->flush();
        }
        return $book;
    }

    /**
     * Lazily backfill a Hardcover audiobook's narrator/runtime, returning true when a fresh value
     * was cached. The modal opens instantly on cached data and calls this asynchronously (via
     * /books/metadata/audio) so the audio facts patch in afterward. No-op — and no upstream call —
     * when the data is already cached, the book isn't a Hardcover audiobook, or we last tried within
     * the cooldown.
     */
    public function ensureAudioMetadata(Book $book): bool
    {
        if (!$this->needsAudioBackfill($book)) {
            return false;
        }
        $this->refresh($book);
        $this->em->flush();
        return true;
    }

    /**
     * True when this is a Hardcover audiobook whose narrator or runtime hasn't been cached yet.
     * The trending poll records availability but not narrator/runtime, so we lazily backfill those.
     * Guarded so print-only works (which never have a narrator) and non-Hardcover sources don't
     * re-hit upstream, and throttled by {@see self::AUDIO_BACKFILL_COOLDOWN} for audiobooks
     * Hardcover simply has no narrator/runtime for.
     */
    private function needsAudioBackfill(Book $book): bool
    {
        if ($book->getSource() !== Book::SOURCE_HARDCOVER || !$book->isAudiobookAvailable()) {
            return false;
        }
        if ($book->getNarrator() !== null && $book->getAudioSeconds() !== null) {
            return false;
        }
        $fetchedAt = $book->getMetadataFetchedAt();
        return $fetchedAt === null || $fetchedAt < new \DateTimeImmutable(self::AUDIO_BACKFILL_COOLDOWN);
    }

    /**
     * Force a re-fetch from upstream regardless of when metadata was last refreshed — backs the
     * modal's manual "refresh metadata" button. Silent on upstream failure (see {@see refresh()}).
     */
    public function forceRefresh(Book $book): void
    {
        $this->refresh($book);
        $this->em->flush();
    }

    /**
     * Silent on upstream failure: row stays untouched (metadataFetchedAt null) so a future click retries.
     */
    private function refresh(Book $book): void
    {
        try {
            $data = match ($book->getSource()) {
                Book::SOURCE_GRIMMORY    => $this->fetchFromGrimmory($book->getExternalId()),
                Book::SOURCE_HARDCOVER   => $this->fetchFromHardcover($book->getExternalId()),
                Book::SOURCE_OPENLIBRARY => $this->openLibrary->fetchBookMetadataByKey($book->getExternalId()),
                default => null,
            };
        } catch (GrimmoryException | HardcoverException | OpenLibraryException) {
            return;
        }
        if ($data === null) {
            return;
        }
        $this->apply($book, $data);
    }

    /** @return array<string, mixed> */
    private function fetchFromGrimmory(string $externalId): array
    {
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            throw new GrimmoryException('Grimmory integration is not enabled.');
        }
        return $this->grimmory->fetchBookMetadata($integration, $externalId);
    }

    /** @return array<string, mixed> */
    private function fetchFromHardcover(string $externalId): array
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled()) {
            throw new HardcoverException('Hardcover integration is not enabled.');
        }
        return $this->hardcover->fetchBookMetadataBySlug($integration, $externalId);
    }

    /** @param array<string, mixed> $data */
    private function apply(Book $book, array $data): void
    {
        if (!empty($data['title'])) {
            $book->setTitle((string) $data['title']);
        }
        if (!empty($data['author'])) {
            $book->setAuthor((string) $data['author']);
        }
        if (array_key_exists('publisher', $data)) {
            $book->setPublisher($data['publisher'] !== null ? (string) $data['publisher'] : null);
        }
        if (array_key_exists('publishedDate', $data)) {
            $book->setPublishedDate($data['publishedDate'] !== null ? (string) $data['publishedDate'] : null);
        }
        if (array_key_exists('language', $data)) {
            $book->setLanguage($data['language'] !== null ? (string) $data['language'] : null);
        }
        if (array_key_exists('description', $data)) {
            $book->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (!empty($data['isbn']) && $book->getIsbn() === null) {
            $book->setIsbn((string) $data['isbn']);
        }
        if (isset($data['genres']) && is_array($data['genres'])) {
            $book->setGenres(array_values(array_filter($data['genres'], 'is_string')));
        }
        if (array_key_exists('series', $data) && $data['series'] !== null) {
            $book->setSeries((string) $data['series']);
        }
        if (array_key_exists('seriesIndex', $data) && $data['seriesIndex'] !== null) {
            $book->setSeriesIndex((string) $data['seriesIndex']);
        }
        if (array_key_exists('seriesTotal', $data)) {
            $book->setSeriesTotal(is_int($data['seriesTotal']) ? $data['seriesTotal'] : null);
        }
        // Availability only flips on — a provider that omits it shouldn't erase a known edition.
        if (!empty($data['audiobookAvailable'])) {
            $book->setAudiobookAvailable(true);
        }
        if (array_key_exists('narrator', $data)) {
            $book->setNarrator($data['narrator'] !== null ? (string) $data['narrator'] : null);
        }
        if (array_key_exists('audioSeconds', $data)) {
            $book->setAudioSeconds(is_int($data['audioSeconds']) ? $data['audioSeconds'] : null);
        }
        $book->setMetadataFetchedAt(new \DateTimeImmutable());
    }
}
