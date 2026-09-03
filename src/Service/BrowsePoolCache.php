<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Integration\Hardcover\Dto\TrendingBook;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Message\RefreshBrowsePool;
use App\Message\TouchBooksSeen;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The cached row pools behind /browse — trending, and one pool per search query+type — shared
 * by {@see \App\Controller\BrowseController} (the read path) and
 * {@see \App\MessageHandler\RefreshBrowsePoolHandler} (the write path).
 *
 * Two rules drive the whole class:
 *
 *  1. A pool never expires. `expiresAfter(null)` on the filesystem `cache.app` adapter means
 *     "no expiry", so once we have rows we can always serve them. Operators who pace their
 *     Hardcover sync at a day or more still want browse to render instantly, and an upstream
 *     outage (Hardcover HTTP 429 is the case that bit us) must never blank a page we already
 *     loaded. Nothing but a refresh that came back with real rows replaces stored rows.
 *  2. A stale pool is served *as-is* and refreshed in the worker. The request that noticed the
 *     staleness dispatches {@see RefreshBrowsePool} and returns the rows it holds; the next
 *     request picks up whatever landed. Only a cold pool (nothing cached at all) fetches
 *     inline, because there is nothing else to serve.
 *
 * Growth (infinite scroll asking for rows past the end of the pool) stays inline and appends,
 * staleness or not: it cannot shrink the pool, and deferring it would just make the grid stop.
 */
final class BrowsePoolCache
{
    /** Hardcover's `books_trending` caps server-side at 100 per request — must match this. */
    public const UPSTREAM_PAGE_SIZE = 100;
    /** Hard ceiling on how deep we paginate trending upstream. Matches roughly Hardcover's own UI. */
    public const UPSTREAM_MAX_ITEMS = 2000;
    /** Hardcover's `search` caps per_page at 25 — must match this or the "short page = exhausted" signal misfires. */
    public const SEARCH_PAGE_SIZE = 25;
    /** Hard ceiling on how deep we paginate search upstream. */
    public const SEARCH_MAX_ITEMS = 1000;

    // v3/v4: pool entries now carry an `audiobook` (Hardcover availability) flag.
    public const CACHE_KEY = 'browse.trending.v3';
    public const SEARCH_CACHE_PREFIX = 'browse.search.v4.';

    /** Marks a pool as having a worker refresh queued, so scrolling can't queue a hundred more. */
    private const INFLIGHT_PREFIX = 'browse.refresh.inflight.';
    /**
     * The in-flight marker MUST expire: a worker that died mid-refresh would otherwise wedge the
     * pool at "refresh already queued" forever and it would never revalidate again.
     */
    private const INFLIGHT_TTL = 300;
    /**
     * After a failed refresh, don't queue another one for this long. Re-queueing on every scroll
     * event is exactly what sustains a rate-limit.
     */
    private const REFRESH_RETRY_BACKOFF = 60;

    public function __construct(
        private readonly HardcoverClient $hardcover,
        private readonly OpenLibraryClient $openLibrary,
        private readonly CacheItemPoolInterface $cache,
        private readonly IntegrationRepository $integrations,
        private readonly BookRepository $bookRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * How old a pool may get before we refresh it in the background, in seconds. Operator
     * setting on the Hardcover integration row (default 2h) — deliberately separate from that
     * row's `syncIntervalMinutes`, which paces RefreshHardcoverTrending and may be as rare as
     * once a day.
     */
    public function refreshAfterSeconds(): int
    {
        $hardcover = $this->integrations->findByKind(Integration::KIND_HARDCOVER);

        return ($hardcover?->getBrowseRefreshAfterMinutes() ?? Integration::DEFAULT_BROWSE_REFRESH_MINUTES) * 60;
    }

    /**
     * Read path for the trending pool: serve what we hold, top it up inline only when the
     * caller needs rows we don't have yet, and hand a stale pool to the worker.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored: bool, stale: bool}
     */
    public function serveTrending(int $needed): array
    {
        return $this->serve(self::CACHE_KEY, null, 'title', $needed, self::UPSTREAM_MAX_ITEMS);
    }

    /**
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored: bool, stale: bool}
     */
    public function serveSearch(string $query, string $type, int $needed): array
    {
        return $this->serve(self::searchKey($query, $type), $query, $type, $needed, self::SEARCH_MAX_ITEMS);
    }

    /**
     * Write path (worker only): rebuild the pool from page 1. Upstream rankings shift, so a
     * refresh replaces the prefix rather than appending to it — {@see finishPool()} splices the
     * rows we already held back on, so a short or failed rebuild can never shrink the pool.
     */
    public function refreshTrending(): void
    {
        $this->refresh(self::CACHE_KEY, null, 'title', self::UPSTREAM_MAX_ITEMS);
    }

    public function refreshSearch(string $query, string $type): void
    {
        $this->refresh(self::searchKey($query, $type), $query, $type, self::SEARCH_MAX_ITEMS);
    }

    public static function searchKey(string $query, string $type): string
    {
        return self::SEARCH_CACHE_PREFIX . sha1($type . '|' . strtolower($query));
    }

    /**
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $stored
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored: bool, stale: bool}
     */
    private function serve(string $key, ?string $query, string $type, int $needed, int $maxItems): array
    {
        $now = time();
        $item = $this->cache->getItem($key);
        $stored = $this->normalizePool($item->isHit() ? $item->get() : null, $now);
        $needed = min($needed, $maxItems);
        $stale = ($now - $stored['fetchedAt']) >= $this->refreshAfterSeconds();

        // Cold pool: there is nothing to serve, so this one request eats the upstream latency.
        if ($stored['books'] === []) {
            return $this->fetchInto($item, $stored, $stored, $needed, $maxItems, $now, $query, $type);
        }

        if ($stale) {
            $this->queueRefresh($key, $stored, $query, $type, $now);
        }

        // Infinite scroll asking past the end of the pool. Appending is additive and offset-based,
        // so it is safe whether or not the pool is stale — and skipping it would end the grid.
        if (!$stored['exhausted'] && count($stored['books']) < $needed) {
            // `revalidates: false` — appending pages does not refresh the prefix, so the pool
            // keeps its old timestamp and the worker rebuild still has work to do.
            $pool = $this->fetchInto($item, $stored, $stored, $needed, $maxItems, $now, $query, $type, false);
            // A grown pool is still stale if its rows predate the threshold: growth appends, it
            // does not revalidate the prefix that the background refresh will replace.
            $pool['stale'] = $pool['stale'] || $stale;

            return $pool;
        }

        return $stored + ['errored' => false, 'stale' => $stale];
    }

    /**
     * Queues one worker refresh for this pool, at most. Two guards, both essential:
     * an in-flight marker (so a scrolling client can't queue a refresh per request) and the
     * post-failure backoff (so a rate-limited pool isn't re-queued every minute of a 429).
     *
     * @param array{fetchedAt: int, failedAt: ?int, ...} $stored
     */
    private function queueRefresh(string $key, array $stored, ?string $query, string $type, int $now): void
    {
        if ($stored['failedAt'] !== null && ($now - $stored['failedAt']) < self::REFRESH_RETRY_BACKOFF) {
            return;
        }

        $marker = $this->cache->getItem(self::INFLIGHT_PREFIX . sha1($key));
        if ($marker->isHit()) {
            return;
        }
        $marker->set($now);
        $marker->expiresAfter(self::INFLIGHT_TTL);
        $this->cache->save($marker);

        $this->bus->dispatch($query === null
            ? new RefreshBrowsePool()
            : new RefreshBrowsePool(RefreshBrowsePool::POOL_SEARCH, $query, $type));
    }

    private function releaseInFlight(string $key): void
    {
        $this->cache->deleteItem(self::INFLIGHT_PREFIX . sha1($key));
    }

    /**
     * The worker's rebuild: start from an empty pool at the depth we already had (so a user who
     * had scrolled deep keeps that depth) and write the result through {@see finishPool()}.
     */
    private function refresh(string $key, ?string $query, string $type, int $maxItems): void
    {
        $now = time();
        $item = $this->cache->getItem($key);
        $stored = $this->normalizePool($item->isHit() ? $item->get() : null, $now);
        $pageSize = $query === null ? self::UPSTREAM_PAGE_SIZE : self::SEARCH_PAGE_SIZE;
        // Rebuild at least one page, and at most as deep as the pool already goes.
        $needed = max($pageSize, min(count($stored['books']), $maxItems));
        $empty = ['source' => $stored['source'], 'books' => [], 'exhausted' => false, 'fetchedAt' => $now, 'failedAt' => null];

        try {
            $this->fetchInto($item, $stored, $empty, $needed, $maxItems, $now, $query, $type);
        } finally {
            // Always release, even on an exception: a wedged marker would stop this pool from
            // ever revalidating again (the TTL is the backstop, not the mechanism).
            $this->releaseInFlight($key);
        }
    }

    /**
     * Pages upstream into $pool until it holds $needed rows, then merges and stores it.
     * `$query === null` means the trending feed; otherwise it's a search pool.
     *
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $stored
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $pool
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored: bool, stale: bool}
     */
    private function fetchInto(CacheItemInterface $item, array $stored, array $pool, int $needed, int $maxItems, int $now, ?string $query, string $type, bool $revalidates = true): array
    {
        $errored = false;
        $pageSize = $query === null ? self::UPSTREAM_PAGE_SIZE : self::SEARCH_PAGE_SIZE;

        $hardcover = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        $hardcoverAvailable = $hardcover !== null && $hardcover->isEnabled() && $hardcover->hasCredentials();

        if ($hardcoverAvailable && $pool['source'] === '') {
            $pool['source'] = Integration::KIND_HARDCOVER;
        }

        // Incremental upstream pagination: keep asking for the next page until the pool covers
        // $needed, the upstream returns short, or we hit the cap.
        while (
            $pool['source'] === Integration::KIND_HARDCOVER
            && !$pool['exhausted']
            && count($pool['books']) < $needed
            && count($pool['books']) < $maxItems
            && $hardcoverAvailable
        ) {
            $offset = count($pool['books']);
            try {
                $books = $query === null
                    ? $this->hardcover->fetchTrending($hardcover, $pageSize, $offset)
                    : $this->hardcover->searchBooks($hardcover, $query, $pageSize, intdiv($offset, $pageSize) + 1, $type);
            } catch (HardcoverException $e) {
                $this->logger->warning('Browse: Hardcover fetch failed', ['q' => $query, 'offset' => $offset, 'error' => $e->getMessage()]);
                $errored = true;
                break;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            $this->writeThrough(Book::SOURCE_HARDCOVER, $books);
            if (count($books) < $pageSize || count($pool['books']) >= $maxItems) {
                $pool['exhausted'] = true;
                break;
            }
        }

        // OpenLibrary fallback only when Hardcover isn't available and the pool is still empty.
        if ($pool['source'] === '' && $pool['books'] === []) {
            $openLibrary = $this->integrations->findByKind(Integration::KIND_OPENLIBRARY);
            if ($openLibrary !== null && $openLibrary->isEnabled()) {
                $errored = $this->fillFromOpenLibrary($pool, $needed, $maxItems, $query, $type) || $errored;
            }
        }

        return $this->finishPool($item, $stored, $pool, $errored, $now, $revalidates);
    }

    /**
     * OpenLibrary half of {@see fetchInto()}. Its trending feed is single-shot (no offset), so
     * one call fills and exhausts the pool; search pages like Hardcover's.
     *
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $pool
     * @return bool whether the fetch errored
     */
    private function fillFromOpenLibrary(array &$pool, int $needed, int $maxItems, ?string $query, string $type): bool
    {
        $pool['source'] = Integration::KIND_OPENLIBRARY;

        if ($query === null) {
            try {
                $books = $this->openLibrary->fetchTrending($maxItems);
            } catch (OpenLibraryException $e) {
                $this->logger->warning('Browse: OpenLibrary trending fetch failed', ['error' => $e->getMessage()]);
                // Leave `source` unset so a later request can still pick a source.
                $pool['source'] = '';

                return true;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            $this->writeThrough(Book::SOURCE_OPENLIBRARY, $books);
            $pool['exhausted'] = true;

            return false;
        }

        while (!$pool['exhausted'] && count($pool['books']) < $needed && count($pool['books']) < $maxItems) {
            $page = intdiv(count($pool['books']), self::SEARCH_PAGE_SIZE) + 1;
            try {
                $books = $this->openLibrary->searchBooks($query, self::SEARCH_PAGE_SIZE, $page, $type);
            } catch (OpenLibraryException $e) {
                $this->logger->warning('Browse: OpenLibrary search failed', ['q' => $query, 'page' => $page, 'error' => $e->getMessage()]);

                return true;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            $this->writeThrough(Book::SOURCE_OPENLIBRARY, $books);
            if (count($books) < self::SEARCH_PAGE_SIZE || count($pool['books']) >= $maxItems) {
                $pool['exhausted'] = true;
                break;
            }
        }

        return false;
    }

    /**
     * Normalizes a cached pool entry into the current shape. Entries written before
     * stale-while-revalidate carry no timestamps: treat those as fetched *now* (so a deploy
     * doesn't stampede upstream with rebuilds) and as never-failed.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int}
     */
    private function normalizePool(mixed $raw, int $now): array
    {
        if (!is_array($raw) || !isset($raw['books']) || !is_array($raw['books'])) {
            return ['source' => '', 'books' => [], 'exhausted' => false, 'fetchedAt' => $now, 'failedAt' => null];
        }

        return [
            'source' => is_string($raw['source'] ?? null) ? $raw['source'] : '',
            'books' => array_values($raw['books']),
            'exhausted' => (bool) ($raw['exhausted'] ?? false),
            'fetchedAt' => is_int($raw['fetchedAt'] ?? null) ? $raw['fetchedAt'] : $now,
            'failedAt' => is_int($raw['failedAt'] ?? null) ? $raw['failedAt'] : null,
        ];
    }

    /**
     * Merges a (re)built pool back over what we already held and writes it out.
     *
     * A refresh that errored — or that simply came back shorter than the pool we hold — must
     * never shrink the data: the stored tail is spliced back onto the refreshed prefix, and the
     * entry keeps its old `fetchedAt` so it stays stale and a later request queues another try.
     * Only a refresh that actually completed restamps the entry fresh. An error that left us
     * with nothing at all is never written: a later retry should fetch rather than pin an empty
     * pool in a cache that no longer expires.
     *
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $stored
     * @param array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int} $pool
     * @param bool $revalidates whether this fetch rebuilt the pool (vs. merely appended to it)
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, fetchedAt: int, failedAt: ?int, errored: bool, stale: bool}
     */
    private function finishPool(CacheItemInterface $item, array $stored, array $pool, bool $errored, int $now, bool $revalidates = true): array
    {
        $got = count($pool['books']);
        if ($got < count($stored['books'])) {
            $pool['books'] = array_merge($pool['books'], array_slice($stored['books'], $got));
            $pool['exhausted'] = $pool['exhausted'] || $stored['exhausted'];
            if ($pool['source'] === '') {
                $pool['source'] = $stored['source'];
            }
        }

        if ($errored) {
            $pool['fetchedAt'] = $stored['fetchedAt'];
            $pool['failedAt'] = $now;
        } else {
            // Only a real rebuild restamps the entry fresh; a growth append keeps the pool's age
            // so the queued background refresh is still the thing that makes it current.
            $pool['fetchedAt'] = ($revalidates || $stored['books'] === []) ? $now : $stored['fetchedAt'];
            $pool['failedAt'] = null;
        }

        if ($pool['books'] !== []) {
            $item->set($pool);
            // No expiry, on purpose: see the class docblock. Stale rows outlive any outage and
            // any sync cadence the operator picks; only real rows ever replace them.
            $item->expiresAfter(null);
            $this->cache->save($item);
        }

        $pool['errored'] = $errored;
        // Serving rows we could not revalidate — the client is told so it can say "cached"
        // rather than silently implying the grid is current.
        $pool['stale'] = $pool['books'] !== [] && ($errored || ($now - $pool['fetchedAt']) >= $this->refreshAfterSeconds());

        return $pool;
    }

    /**
     * Upsert every upstream book result into the local `books` table (with `downloaded=false`
     * if it isn't there yet) and bump `last_seen_at`. The home page and request flow then see
     * trending/search results as first-class rows. Entries without a stable slug are skipped —
     * the upstream feed can't always emit one and we don't want duplicate metadata-only rows.
     *
     * @param list<TrendingBook> $books
     */
    private function writeThrough(string $source, array $books): void
    {
        if ($books === []) {
            return;
        }
        $now = new \DateTimeImmutable();
        $ids = [];
        foreach ($books as $b) {
            $slug = $this->slugFromExternalUrl($source, $b->externalUrl);
            if ($slug === null) {
                continue;
            }
            $book = $this->bookRepo->upsertMetadataBook(
                source: $source,
                externalId: $slug,
                title: $b->title,
                author: $b->author,
                externalUrl: $b->externalUrl,
                coverUrl: $b->coverUrl,
                rawIsbns: $b->isbns,
                now: $now,
                audiobookAvailable: $b->audiobook,
            );
            if ($book->getId() === null) {
                $this->em->flush();
            }
            $ids[] = (int) $book->getId();
        }
        $this->em->flush();
        if ($ids !== []) {
            $this->bus->dispatch(new TouchBooksSeen($ids));
        }
    }

    private function slugFromExternalUrl(string $source, ?string $externalUrl): ?string
    {
        if ($externalUrl === null || $externalUrl === '') {
            return null;
        }
        $path = parse_url($externalUrl, PHP_URL_PATH) ?: '';
        // `books.external_id` holds 500 chars; a longer slug would abort the whole batch's
        // flush, so treat it like a missing slug and skip that entry instead.
        if ($source === Book::SOURCE_HARDCOVER && preg_match('~/books/([^/?#]+)~', $path, $m)) {
            return \strlen($m[1]) > 500 ? null : $m[1];
        }
        if ($source === Book::SOURCE_OPENLIBRARY && preg_match('~/works/(OL[A-Z0-9]+W)~', $path, $m)) {
            return $m[1];
        }
        return null;
    }
}
