<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Integration\Hardcover\Dto\TrendingBook;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Message\TouchBooksSeen;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\IntegrationRepository;
use App\Service\BookRecommendationService;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class BrowseController extends AbstractController
{
    private const MAX_LIMIT = 240;
    /** Hardcover's `books_trending` caps server-side at 100 per request — must match this. */
    private const UPSTREAM_PAGE_SIZE = 100;
    /** Hard ceiling on how deep we paginate trending upstream. Matches roughly Hardcover's own UI. */
    private const UPSTREAM_MAX_ITEMS = 2000;
    private const CACHE_TTL = 300;
    // v3/v4: pool entries now carry an `audiobook` (Hardcover availability) flag.
    private const CACHE_KEY = 'browse.trending.v3';
    private const SEARCH_CACHE_PREFIX = 'browse.search.v4.';
    /** Hardcover's `search` caps per_page at 25 — must match this or the "short page = exhausted" signal misfires. */
    private const SEARCH_PAGE_SIZE = 25;
    /** Hard ceiling on how deep we paginate search upstream. */
    private const SEARCH_MAX_ITEMS = 1000;
    private const SEARCH_TYPES = ['title', 'author', 'genre', 'series', 'publisher'];

    public function __construct(
        private readonly CoverCache $covers,
        private readonly HardcoverClient $hardcover,
        private readonly OpenLibraryClient $openLibrary,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly BookRepository $bookRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/browse', name: 'browse')]
    public function index(): Response
    {
        return $this->render('browse/index.html.twig');
    }

    #[Route('/browse/items', name: 'browse_items', methods: ['GET'])]
    public function items(Request $request, BookRepository $books, IntegrationRepository $integrations, BookRequestRepository $requests): JsonResponse
    {
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit  = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 100)));
        $sort   = (string) $request->query->get('sort', 'trending');
        $dir    = strtoupper((string) $request->query->get('dir', 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        $audiobookOnly = $request->query->get('format') === 'audiobook';

        // Owned/"downloaded" matching follows the toggle: in audiobook mode only owned audio
        // copies count; in book mode any owned format counts (current behavior).
        $libraryIsbns = $books->downloadedIsbns($audiobookOnly ? true : null);
        $libraryKeys  = $books->downloadedTitleAuthorKeys($audiobookOnly ? true : null);
        $statusMaps = $this->statusMaps($requests, $audiobookOnly ? true : null);

        // For trending sort: only fetch as much upstream as we need to satisfy this slice.
        // For non-trending sorts (title/author): fetch *everything* up to the cap so the
        // sort is over the full pool — otherwise sort order would shift as the user scrolls.
        [$cards, $pool] = $this->loadFilteredCards(
            fn (int $n) => $this->ensureUpstream($integrations, $n),
            $sort === 'trending' ? ($offset + $limit) : self::UPSTREAM_MAX_ITEMS,
            self::UPSTREAM_MAX_ITEMS,
            $offset + $limit,
            $audiobookOnly,
            $libraryIsbns,
            $libraryKeys,
            $statusMaps,
        );

        $this->sortCards($cards, $sort, $dir);

        $slice = array_slice($cards, $offset, $limit);
        $total = count($cards);
        $exhausted = $pool['exhausted'] ?? false;
        // See search(): on upstream error, never report has_more or the client tight-loops.
        $errored = $pool['errored'] ?? false;

        return new JsonResponse([
            'items' => $slice,
            'next_offset' => $offset + count($slice),
            // For trending: there may be more upstream pages even if we've returned what's loaded.
            // For sorted: we already loaded the full pool, so end-of-pool is end-of-list.
            'has_more' => $errored
                ? false
                : ($sort === 'trending'
                    ? (!$exhausted || ($offset + count($slice)) < $total)
                    : (($offset + count($slice)) < $total)),
        ]);
    }

    #[Route('/browse/search', name: 'browse_search', methods: ['GET'])]
    public function search(Request $request, BookRepository $books, IntegrationRepository $integrations, BookRequestRepository $requests): JsonResponse
    {
        $q      = trim((string) $request->query->get('q', ''));
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit  = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 100)));
        $sort   = (string) $request->query->get('sort', 'trending');
        $dir    = strtoupper((string) $request->query->get('dir', 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        $type   = (string) $request->query->get('type', 'title');
        if (!in_array($type, self::SEARCH_TYPES, true)) {
            $type = 'title';
        }
        $audiobookOnly = $request->query->get('format') === 'audiobook';

        if ($q === '') {
            return new JsonResponse(['items' => [], 'next_offset' => 0, 'has_more' => false]);
        }

        $libraryIsbns = $books->downloadedIsbns($audiobookOnly ? true : null);
        $libraryKeys  = $books->downloadedTitleAuthorKeys($audiobookOnly ? true : null);
        $statusMaps = $this->statusMaps($requests, $audiobookOnly ? true : null);

        // For non-trending sorts we need the full pool to sort over it; trending/relevance
        // only needs enough rows to satisfy the current slice.
        [$cards, $pool] = $this->loadFilteredCards(
            fn (int $n) => $this->ensureSearchPool($integrations, $q, $type, $n),
            $sort === 'trending' ? ($offset + $limit) : self::SEARCH_MAX_ITEMS,
            self::SEARCH_MAX_ITEMS,
            $offset + $limit,
            $audiobookOnly,
            $libraryIsbns,
            $libraryKeys,
            $statusMaps,
        );

        // Trending sort is meaningless on a search pool — fall back to the upstream
        // result order, which is relevance.
        if ($sort !== 'trending') {
            $this->sortCards($cards, $sort, $dir);
        } elseif ($dir === 'DESC') {
            $cards = array_reverse($cards);
        }

        $slice = array_slice($cards, $offset, $limit);
        $total = count($cards);
        $exhausted = $pool['exhausted'] ?? false;
        // An upstream error (e.g. Hardcover HTTP 429) leaves the pool empty or partial.
        // Never report has_more in that case — otherwise the client re-requests the same
        // empty page in a tight loop, which hammers upstream and sustains the rate-limit.
        $errored = $pool['errored'] ?? false;

        return new JsonResponse([
            'items' => $slice,
            'next_offset' => $offset + count($slice),
            'has_more' => $errored
                ? false
                : ($sort === 'trending'
                    ? (!$exhausted || ($offset + count($slice)) < $total)
                    : (($offset + count($slice)) < $total)),
        ]);
    }

    /**
     * "More like this": recommendations for a seed book, rendered through the same card UI as
     * search. The seed is the *opened* book's internal id; {@see BookRecommendationService}
     * resolves it to a Hardcover slug, computes/serves the list-co-occurrence set, and persists
     * it. The full set is pre-ranked and already loaded, so pagination is a plain slice.
     */
    #[Route('/browse/similar', name: 'browse_similar', methods: ['GET'])]
    public function similar(Request $request, BookRepository $books, BookRequestRepository $requests, BookRecommendationService $recommendations): JsonResponse
    {
        $seedId = $request->query->getInt('seed', 0);
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit  = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 100)));
        $audiobookOnly = $request->query->get('format') === 'audiobook';

        if ($seedId <= 0) {
            return new JsonResponse(['items' => [], 'next_offset' => 0, 'has_more' => false]);
        }
        $seed = $books->find($seedId);
        if ($seed === null) {
            return new JsonResponse(['items' => [], 'next_offset' => 0, 'has_more' => false]);
        }

        // The recommendation pool is pre-built and fully loaded, so no upstream top-up here —
        // just filter and slice. Persisted (DB-backed) recommendations carry no Hardcover
        // audiobook flag, so they won't appear in audiobook mode (known limitation).
        $pool = $recommendations->poolFor($seed);

        $libraryIsbns = $books->downloadedIsbns($audiobookOnly ? true : null);
        $libraryKeys  = $books->downloadedTitleAuthorKeys($audiobookOnly ? true : null);
        $statusMaps = $this->statusMaps($requests, $audiobookOnly ? true : null);
        $cards = $this->normalizeCards($pool, $libraryIsbns, $libraryKeys, $statusMaps, $audiobookOnly);

        $slice = array_slice($cards, $offset, $limit);
        $total = count($cards);

        return new JsonResponse([
            'items' => $slice,
            'next_offset' => $offset + count($slice),
            'has_more' => ($offset + count($slice)) < $total,
        ]);
    }

    /**
     * Loads (and caches) at least $needed search results, paginating upstream incrementally.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored?: bool}
     */
    private function ensureSearchPool(IntegrationRepository $integrations, string $query, string $type, int $needed): array
    {
        $key = self::SEARCH_CACHE_PREFIX . sha1($type . '|' . strtolower($query));
        $item = $this->cache->getItem($key);
        /** @var array{source: string, books: list<array<string, mixed>>, exhausted: bool}|null $pool */
        $pool = $item->isHit() ? $item->get() : null;
        if (!is_array($pool) || !isset($pool['books'])) {
            $pool = ['source' => '', 'books' => [], 'exhausted' => false];
        }

        $needed = min($needed, self::SEARCH_MAX_ITEMS);
        $errored = false;

        $hardcover = $integrations->findByKind(Integration::KIND_HARDCOVER);
        $hardcoverAvailable = $hardcover !== null && $hardcover->isEnabled() && $hardcover->hasCredentials();

        if ($hardcoverAvailable && $pool['source'] === '') {
            $pool['source'] = Integration::KIND_HARDCOVER;
        }

        while (
            $pool['source'] === Integration::KIND_HARDCOVER
            && !$pool['exhausted']
            && count($pool['books']) < $needed
            && count($pool['books']) < self::SEARCH_MAX_ITEMS
            && $hardcoverAvailable
        ) {
            $page = intdiv(count($pool['books']), self::SEARCH_PAGE_SIZE) + 1;
            try {
                $books = $this->hardcover->searchBooks($hardcover, $query, self::SEARCH_PAGE_SIZE, $page, $type);
            } catch (HardcoverException $e) {
                $this->logger->warning('Browse: Hardcover search failed', ['q' => $query, 'page' => $page, 'error' => $e->getMessage()]);
                $errored = true;
                break;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            $this->writeThrough(Book::SOURCE_HARDCOVER, $books);
            if (count($books) < self::SEARCH_PAGE_SIZE) {
                $pool['exhausted'] = true;
                break;
            }
            if (count($pool['books']) >= self::SEARCH_MAX_ITEMS) {
                $pool['exhausted'] = true;
                break;
            }
        }

        // OpenLibrary fallback only when Hardcover isn't available and the pool is still empty.
        if ($pool['source'] === '' && $pool['books'] === []) {
            $openLibrary = $integrations->findByKind(Integration::KIND_OPENLIBRARY);
            if ($openLibrary !== null && $openLibrary->isEnabled()) {
                $pool['source'] = Integration::KIND_OPENLIBRARY;
                while (
                    !$pool['exhausted']
                    && count($pool['books']) < $needed
                    && count($pool['books']) < self::SEARCH_MAX_ITEMS
                ) {
                    $page = intdiv(count($pool['books']), self::SEARCH_PAGE_SIZE) + 1;
                    try {
                        $books = $this->openLibrary->searchBooks($query, self::SEARCH_PAGE_SIZE, $page, $type);
                    } catch (OpenLibraryException $e) {
                        $this->logger->warning('Browse: OpenLibrary search failed', ['q' => $query, 'page' => $page, 'error' => $e->getMessage()]);
                        $errored = true;
                        break;
                    }
                    foreach ($books as $b) {
                        $pool['books'][] = $b->toArray();
                    }
                    $this->writeThrough(Book::SOURCE_OPENLIBRARY, $books);
                    if (count($books) < self::SEARCH_PAGE_SIZE) {
                        $pool['exhausted'] = true;
                        break;
                    }
                    if (count($pool['books']) >= self::SEARCH_MAX_ITEMS) {
                        $pool['exhausted'] = true;
                        break;
                    }
                }
            }
        }

        // Don't cache a pool left empty purely by an upstream error — a later retry (after
        // the rate-limit clears) should fetch fresh rather than serve nothing for the full TTL.
        if (!($errored && $pool['books'] === [])) {
            $item->set($pool);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        $pool['errored'] = $errored;

        return $pool;
    }

    /**
     * Fetch a pool and shape it into cards, growing the pool until it yields enough *filtered*
     * cards or the upstream is exhausted. The filter (audiobook-only) is applied at the card
     * layer, so each upstream page can return fewer cards than rows; in audiobook mode we keep
     * paging until `$wanted` audiobook cards exist (or we hit the cap / exhaust the feed).
     *
     * @param callable(int): array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored?: bool} $fetchPool
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function loadFilteredCards(
        callable $fetchPool,
        int $baseNeeded,
        int $maxItems,
        int $wanted,
        bool $audiobookOnly,
        array $libraryIsbns,
        array $libraryKeys,
        array $statusMaps,
    ): array {
        $needed = $baseNeeded;
        while (true) {
            $pool = $fetchPool($needed);
            $cards = $this->normalizeCards($pool, $libraryIsbns, $libraryKeys, $statusMaps, $audiobookOnly);
            if (!$audiobookOnly
                || count($cards) >= $wanted
                || ($pool['exhausted'] ?? false)
                || ($pool['errored'] ?? false)
                || $needed >= $maxItems
            ) {
                return [$cards, $pool];
            }
            $needed = min($maxItems, $needed + self::UPSTREAM_PAGE_SIZE);
        }
    }

    /**
     * Loads (and caches) at least $needed items from the upstream trending feed.
     * Appends pages incrementally so we never re-fetch what's already in the pool.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored?: bool}
     */
    private function ensureUpstream(IntegrationRepository $integrations, int $needed): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        /** @var array{source: string, books: list<array<string, mixed>>, exhausted: bool}|null $pool */
        $pool = $item->isHit() ? $item->get() : null;
        if (!is_array($pool) || !isset($pool['books'])) {
            $pool = ['source' => '', 'books' => [], 'exhausted' => false];
        }

        $needed = min($needed, self::UPSTREAM_MAX_ITEMS);
        $errored = false;

        $hardcover = $integrations->findByKind(Integration::KIND_HARDCOVER);
        $hardcoverAvailable = $hardcover !== null && $hardcover->isEnabled() && $hardcover->hasCredentials();

        if ($hardcoverAvailable && $pool['source'] === '') {
            $pool['source'] = Integration::KIND_HARDCOVER;
        }

        // Incremental upstream pagination: keep asking for the next 100-book page
        // until the pool covers $needed, the upstream returns short, or we hit the cap.
        while (
            $pool['source'] === Integration::KIND_HARDCOVER
            && !$pool['exhausted']
            && count($pool['books']) < $needed
            && count($pool['books']) < self::UPSTREAM_MAX_ITEMS
            && $hardcoverAvailable
        ) {
            $offset = count($pool['books']);
            try {
                $books = $this->hardcover->fetchTrending($hardcover, self::UPSTREAM_PAGE_SIZE, $offset);
            } catch (HardcoverException $e) {
                $this->logger->warning('Browse: Hardcover trending fetch failed', ['offset' => $offset, 'error' => $e->getMessage()]);
                $errored = true;
                break;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            $this->writeThrough(Book::SOURCE_HARDCOVER, $books);
            if (count($books) < self::UPSTREAM_PAGE_SIZE) {
                $pool['exhausted'] = true;
                break;
            }
            if (count($pool['books']) >= self::UPSTREAM_MAX_ITEMS) {
                $pool['exhausted'] = true;
                break;
            }
        }

        // OpenLibrary fallback only kicks in when there's no Hardcover at all and
        // the pool is still empty — OpenLibrary trending is single-shot, no offset.
        if ($pool['source'] === '' && $pool['books'] === []) {
            $openLibrary = $integrations->findByKind(Integration::KIND_OPENLIBRARY);
            if ($openLibrary !== null && $openLibrary->isEnabled()) {
                try {
                    $books = $this->openLibrary->fetchTrending(self::UPSTREAM_MAX_ITEMS);
                    foreach ($books as $b) {
                        $pool['books'][] = $b->toArray();
                    }
                    $this->writeThrough(Book::SOURCE_OPENLIBRARY, $books);
                    $pool['source'] = Integration::KIND_OPENLIBRARY;
                    $pool['exhausted'] = true;
                } catch (OpenLibraryException $e) {
                    $this->logger->warning('Browse: OpenLibrary trending fetch failed', ['error' => $e->getMessage()]);
                    $errored = true;
                }
            }
        }

        // Don't cache a pool left empty purely by an upstream error (see ensureSearchPool).
        if (!($errored && $pool['books'] === [])) {
            $item->set($pool);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        $pool['errored'] = $errored;

        return $pool;
    }

    /**
     * @param array{source: string, books: list<array<string, mixed>>} $pool
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @return list<array<string, mixed>>
     */
    private function normalizeCards(array $pool, array $libraryIsbns, array $libraryKeys, array $statusMaps, bool $audiobookOnly = false): array
    {
        $source = $pool['source'] ?? '';
        $out = [];
        foreach ($pool['books'] as $b) {
            if (empty($b['title'])) {
                continue;
            }
            $audiobook = (bool) ($b['audiobook'] ?? false);
            // Audiobook mode: drop works Hardcover doesn't list an audio edition for.
            if ($audiobookOnly && !$audiobook) {
                continue;
            }
            $title = (string) $b['title'];
            $author = isset($b['author']) ? (string) $b['author'] : null;
            $isbns = is_array($b['isbns'] ?? null) ? $b['isbns'] : [];

            $downloaded = false;
            foreach ($isbns as $isbn) {
                if (!is_string($isbn) && !is_int($isbn)) continue;
                if (isset($libraryIsbns[(string) $isbn])) { $downloaded = true; break; }
            }
            $taKey = BookRepository::normalizeTitleAuthor($title, $author);
            if (!$downloaded && $taKey !== null && isset($libraryKeys[$taKey])) {
                $downloaded = true;
            }

            $requestStatus = null;
            foreach ($isbns as $isbn) {
                if (!is_string($isbn) && !is_int($isbn)) continue;
                $key = (string) $isbn;
                if (isset($statusMaps['isbns'][$key])) {
                    $requestStatus = $statusMaps['isbns'][$key];
                    break;
                }
            }
            if ($requestStatus === null && $taKey !== null && isset($statusMaps['titleAuthor'][$taKey])) {
                $requestStatus = $statusMaps['titleAuthor'][$taKey];
            }
            if ($requestStatus === 'available') {
                $downloaded = true;
                $requestStatus = null;
            }

            $remoteCover = $b['coverUrl'] ?? null;
            $externalUrl = is_string($b['externalUrl'] ?? null) ? $b['externalUrl'] : null;
            [$metaSource, $metaExternalId] = $this->trendingMetadataKey($source, $externalUrl);

            $out[] = [
                'title' => $title,
                'author' => $author,
                'downloaded' => $downloaded,
                'request_status' => $requestStatus,
                'cover_url' => is_string($remoteCover) && $remoteCover !== ''
                    ? $this->covers->proxyUrlForRemote($remoteCover)
                    : null,
                'external_url' => $externalUrl,
                'meta_source' => $metaSource,
                'meta_external_id' => $metaExternalId,
                'audiobook' => $audiobook,
            ];
        }
        return $out;
    }

    /**
     * @return array{isbns: array<string, string>, titleAuthor: array<string, string>}
     */
    private function statusMaps(BookRequestRepository $requests, ?bool $audiobook = null): array
    {
        $user = $this->getUser();
        return $user instanceof User
            ? $requests->statusMapsForUser($user, $audiobook)
            : ['isbns' => [], 'titleAuthor' => []];
    }

    /**
     * @param list<array<string, mixed>> $cards
     */
    private function sortCards(array &$cards, string $sort, string $dir): void
    {
        if ($sort === 'trending') {
            if ($dir === 'DESC') {
                $cards = array_reverse($cards);
            }
            return;
        }
        $factor = $dir === 'DESC' ? -1 : 1;
        $field = $sort === 'author' ? 'author' : 'title';
        usort($cards, function (array $a, array $b) use ($field, $factor) {
            $ka = strtolower((string) ($a[$field] ?? ''));
            $kb = strtolower((string) ($b[$field] ?? ''));
            return $factor * strcmp($ka, $kb);
        });
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
        if ($source === Book::SOURCE_HARDCOVER && preg_match('~/books/([^/?#]+)~', $path, $m)) {
            return $m[1];
        }
        if ($source === Book::SOURCE_OPENLIBRARY && preg_match('~/works/(OL[A-Z0-9]+W)~', $path, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function trendingMetadataKey(string $integrationKind, ?string $externalUrl): array
    {
        if ($externalUrl === null || $externalUrl === '') {
            return [null, null];
        }
        $path = parse_url($externalUrl, PHP_URL_PATH) ?: '';
        if ($integrationKind === Integration::KIND_HARDCOVER && preg_match('~/books/([^/?#]+)~', $path, $m)) {
            return [Book::SOURCE_HARDCOVER, $m[1]];
        }
        if ($integrationKind === Integration::KIND_OPENLIBRARY && preg_match('~/works/(OL[A-Z0-9]+W)~', $path, $m)) {
            return [Book::SOURCE_OPENLIBRARY, $m[1]];
        }
        return [null, null];
    }
}
