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
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BrowseController extends AbstractController
{
    private const MAX_LIMIT = 240;
    /** Hardcover's `books_trending` caps server-side at 100 per request — must match this. */
    private const UPSTREAM_PAGE_SIZE = 100;
    /** Hard ceiling on how deep we paginate trending upstream. Matches roughly Hardcover's own UI. */
    private const UPSTREAM_MAX_ITEMS = 2000;
    private const CACHE_TTL = 300;
    private const CACHE_KEY = 'browse.trending.v2';
    private const SEARCH_CACHE_PREFIX = 'browse.search.v3.';
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
    ) {
    }

    #[Route('/browse', name: 'browse')]
    public function index(): Response
    {
        return $this->render('browse/index.html.twig');
    }

    #[Route('/browse/items', name: 'browse_items', methods: ['GET'])]
    public function items(Request $request, BookRepository $books, IntegrationRepository $integrations): JsonResponse
    {
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit  = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 100)));
        $sort   = (string) $request->query->get('sort', 'trending');
        $dir    = strtoupper((string) $request->query->get('dir', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        // For trending sort: only fetch as much upstream as we need to satisfy this slice.
        // For non-trending sorts (title/author): fetch *everything* up to the cap so the
        // sort is over the full pool — otherwise sort order would shift as the user scrolls.
        $needed = $sort === 'trending' ? ($offset + $limit) : self::UPSTREAM_MAX_ITEMS;
        $pool = $this->ensureUpstream($integrations, $needed);

        $libraryIsbns = $books->downloadedIsbns();
        $libraryKeys  = $books->downloadedTitleAuthorKeys();
        $cards = $this->normalizeCards($pool, $libraryIsbns, $libraryKeys);

        $this->sortCards($cards, $sort, $dir);

        $slice = array_slice($cards, $offset, $limit);
        $total = count($cards);
        $exhausted = $pool['exhausted'] ?? false;

        return new JsonResponse([
            'items' => $slice,
            'next_offset' => $offset + count($slice),
            // For trending: there may be more upstream pages even if we've returned what's loaded.
            // For sorted: we already loaded the full pool, so end-of-pool is end-of-list.
            'has_more' => $sort === 'trending'
                ? (!$exhausted || ($offset + count($slice)) < $total)
                : (($offset + count($slice)) < $total),
        ]);
    }

    #[Route('/browse/search', name: 'browse_search', methods: ['GET'])]
    public function search(Request $request, BookRepository $books, IntegrationRepository $integrations): JsonResponse
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

        if ($q === '') {
            return new JsonResponse(['items' => [], 'next_offset' => 0, 'has_more' => false]);
        }

        // For non-trending sorts we need the full pool to sort over it; trending/relevance
        // only needs enough rows to satisfy the current slice.
        $needed = $sort === 'trending' ? ($offset + $limit) : self::SEARCH_MAX_ITEMS;
        $pool = $this->ensureSearchPool($integrations, $q, $type, $needed);

        $libraryIsbns = $books->downloadedIsbns();
        $libraryKeys  = $books->downloadedTitleAuthorKeys();
        $cards = $this->normalizeCards($pool, $libraryIsbns, $libraryKeys);

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

        return new JsonResponse([
            'items' => $slice,
            'next_offset' => $offset + count($slice),
            'has_more' => $sort === 'trending'
                ? (!$exhausted || ($offset + count($slice)) < $total)
                : (($offset + count($slice)) < $total),
        ]);
    }

    /**
     * Loads (and caches) at least $needed search results, paginating upstream incrementally.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool}
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
                break;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            if (count($books) < self::SEARCH_PAGE_SIZE) {
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
                        break;
                    }
                    foreach ($books as $b) {
                        $pool['books'][] = $b->toArray();
                    }
                    if (count($books) < self::SEARCH_PAGE_SIZE) {
                        $pool['exhausted'] = true;
                        break;
                    }
                }
            }
        }

        $item->set($pool);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $pool;
    }

    /**
     * Loads (and caches) at least $needed items from the upstream trending feed.
     * Appends pages incrementally so we never re-fetch what's already in the pool.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool}
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
                break;
            }
            foreach ($books as $b) {
                $pool['books'][] = $b->toArray();
            }
            if (count($books) < self::UPSTREAM_PAGE_SIZE) {
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
                    $pool['source'] = Integration::KIND_OPENLIBRARY;
                    $pool['exhausted'] = true;
                } catch (OpenLibraryException $e) {
                    $this->logger->warning('Browse: OpenLibrary trending fetch failed', ['error' => $e->getMessage()]);
                }
            }
        }

        $item->set($pool);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $pool;
    }

    /**
     * @param array{source: string, books: list<array<string, mixed>>} $pool
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @return list<array<string, mixed>>
     */
    private function normalizeCards(array $pool, array $libraryIsbns, array $libraryKeys): array
    {
        $source = $pool['source'] ?? '';
        $out = [];
        foreach ($pool['books'] as $b) {
            if (empty($b['title'])) {
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
            if (!$downloaded && $isbns === []) {
                $key = BookRepository::normalizeTitleAuthor($title, $author);
                $downloaded = $key !== null && isset($libraryKeys[$key]);
            }

            $remoteCover = $b['coverUrl'] ?? null;
            $externalUrl = is_string($b['externalUrl'] ?? null) ? $b['externalUrl'] : null;
            [$metaSource, $metaExternalId] = $this->trendingMetadataKey($source, $externalUrl);

            $out[] = [
                'title' => $title,
                'author' => $author,
                'downloaded' => $downloaded,
                'cover_url' => is_string($remoteCover) && $remoteCover !== ''
                    ? $this->covers->proxyUrlForRemote($remoteCover)
                    : null,
                'external_url' => $externalUrl,
                'meta_source' => $metaSource,
                'meta_external_id' => $metaExternalId,
            ];
        }
        return $out;
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
