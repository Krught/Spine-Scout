<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Repository\BookRecommendationRepository;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * "More like this": computes Hardcover list-co-occurrence recommendations for a seed book,
 * persists the ranked result in `book_recommendations`, and serves it back as a card pool
 * shaped like the browse search/trending pools so {@see BrowseController} can reuse the same
 * rendering path.
 *
 * Recommendations are keyed by the *opened* book's internal id (any source). For non-Hardcover
 * seeds we resolve a Hardcover slug by title once and memoize it in the cache pool, so the
 * "More like this" button can be shown only for books we can actually recommend against.
 */
final class BookRecommendationService
{
    /** Recompute once the stored set is older than this (durable cache, refreshed lazily). */
    private const FRESH_TTL_DAYS = 30;
    private const RESULT_LIMIT   = 60;
    /** Memoized seed-slug resolution for non-Hardcover books (incl. an empty "no match" sentinel). */
    private const SLUG_CACHE_PREFIX = 'rec.seedslug.';
    private const SLUG_CACHE_TTL    = 60 * 60 * 24 * 30;

    public function __construct(
        private readonly BookRepository $books,
        private readonly BookRecommendationRepository $recs,
        private readonly IntegrationRepository $integrations,
        private readonly HardcoverClient $hardcover,
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The seed id to hand the "More like this" button, or null when the book can't be
     * recommended against (no resolvable Hardcover record). Free for Hardcover-source books;
     * one memoized title lookup for everything else.
     */
    public function recommendSeedRef(Book $book): ?int
    {
        $id = $book->getId();
        if ($id === null) {
            return null;
        }
        return $this->resolveSlug($book) !== null ? $id : null;
    }

    /**
     * Card pool of recommendations for a seed, in the shape {@see BrowseController::normalizeCards()}
     * consumes. Serves the persisted set when fresh; otherwise recomputes (write-through), stores
     * it, and returns the fresh list. Falls back to a stale set when upstream is unavailable.
     *
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool, errored?: bool}
     */
    public function poolFor(Book $seed): array
    {
        $empty = ['source' => Book::SOURCE_HARDCOVER, 'books' => [], 'exhausted' => true];

        $slug = $this->resolveSlug($seed);
        $seedId = $seed->getId();
        if ($slug === null || $seedId === null) {
            return $empty;
        }

        $computedAt = $this->recs->computedAtForSeed($seedId);
        $fresh = $computedAt !== null && $computedAt > new \DateTimeImmutable('-' . self::FRESH_TTL_DAYS . ' days');
        if ($fresh) {
            return $this->poolFromBooks($this->recs->findForSeed($seedId, self::RESULT_LIMIT));
        }

        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasCredentials()) {
            // No upstream to (re)compute against — serve a stale set if we have one.
            return $computedAt !== null
                ? $this->poolFromBooks($this->recs->findForSeed($seedId, self::RESULT_LIMIT))
                : $empty;
        }

        try {
            $similar = $this->hardcover->fetchSimilarBooks($integration, $slug, self::RESULT_LIMIT);
        } catch (HardcoverException $e) {
            $this->logger->warning('Recommendations: Hardcover similar fetch failed', ['slug' => $slug, 'error' => $e->getMessage()]);
            if ($computedAt !== null) {
                return $this->poolFromBooks($this->recs->findForSeed($seedId, self::RESULT_LIMIT));
            }
            return $empty + ['errored' => true];
        }

        // Write-through: persist each recommended book (downloaded=false if new), then store the
        // ranked id list so subsequent opens are pure DB reads. Mirrors BrowseController::writeThrough.
        $now = new \DateTimeImmutable();
        $ids = [];
        foreach ($similar as $b) {
            $recSlug = $this->slugFromHardcoverUrl($b->externalUrl);
            if ($recSlug === null) {
                continue;
            }
            $book = $this->books->upsertMetadataBook(
                source: Book::SOURCE_HARDCOVER,
                externalId: $recSlug,
                title: $b->title,
                author: $b->author,
                externalUrl: $b->externalUrl,
                coverUrl: $b->coverUrl,
                rawIsbns: $b->isbns,
                now: $now,
            );
            if ($book->getId() === null) {
                $this->em->flush();
            }
            $ids[] = (int) $book->getId();
        }
        $this->em->flush();
        $this->recs->replaceForSeed($seedId, $ids, $now);

        return $this->poolFromTrending($similar);
    }

    /**
     * Hardcover slug for the seed, or null if it can't be recommended against. Hardcover-source
     * books are their own slug (no API); others are resolved by a title search, memoized.
     */
    public function resolveSlug(Book $seed): ?string
    {
        if ($seed->getSource() === Book::SOURCE_HARDCOVER) {
            return $seed->getExternalId();
        }

        $seedId = $seed->getId();
        $cacheKey = self::SLUG_CACHE_PREFIX . ($seedId ?? sha1($seed->getSource() . '|' . $seed->getExternalId()));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $value = $item->get();
            return is_string($value) && $value !== '' ? $value : null;
        }

        $slug = $this->lookupSlug($seed);
        if ($slug === false) {
            // Couldn't reach upstream — don't cache, so a later open retries.
            return null;
        }
        // Cache the result, including the empty "no match" sentinel.
        $item->set($slug ?? '');
        $item->expiresAfter(self::SLUG_CACHE_TTL);
        $this->cache->save($item);
        return $slug;
    }

    /**
     * @return string|null|false slug on confident match, null when reached upstream but no match,
     *                           false when upstream was unavailable (caller must not cache).
     */
    private function lookupSlug(Book $seed): string|null|false
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasCredentials()) {
            return false;
        }
        $title = $seed->getTitle();
        if (trim($title) === '') {
            return null;
        }
        try {
            $results = $this->hardcover->searchBooks($integration, $title, 5, 1, 'title');
        } catch (HardcoverException) {
            return false;
        }

        $seedTitleNorm = $this->norm($title);
        $seedAuthorNorm = $seed->getAuthor() !== null ? $this->norm($seed->getAuthor()) : '';
        $fallback = null;
        foreach ($results as $r) {
            if ($this->norm($r->title) !== $seedTitleNorm) {
                continue; // require an exact normalized-title match — never recommend off a fuzzy hit
            }
            $slug = $this->slugFromHardcoverUrl($r->externalUrl);
            if ($slug === null) {
                continue;
            }
            if ($seedAuthorNorm === '') {
                return $slug; // no author to disambiguate; title match is enough
            }
            $rAuthor = $r->author !== null ? $this->norm($r->author) : '';
            if ($rAuthor !== '' && (str_contains($rAuthor, $seedAuthorNorm) || str_contains($seedAuthorNorm, $rAuthor))) {
                return $slug;
            }
            $fallback ??= $slug; // title matched but author didn't line up — weak fallback
        }
        return $fallback;
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';
    }

    private function slugFromHardcoverUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        return preg_match('~/books/([^/?#]+)~', $path, $m) ? $m[1] : null;
    }

    /**
     * @param list<\App\Integration\Hardcover\Dto\TrendingBook> $books
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool}
     */
    private function poolFromTrending(array $books): array
    {
        return [
            'source' => Book::SOURCE_HARDCOVER,
            'books' => array_map(static fn ($b) => $b->toArray(), $books),
            'exhausted' => true,
        ];
    }

    /**
     * @param list<Book> $books
     * @return array{source: string, books: list<array<string, mixed>>, exhausted: bool}
     */
    private function poolFromBooks(array $books): array
    {
        $out = [];
        foreach ($books as $b) {
            $out[] = [
                'title' => $b->getTitle(),
                'author' => $b->getAuthor(),
                'coverUrl' => $b->getCoverUrl(),
                'externalUrl' => $b->getExternalUrl(),
                'isbns' => $b->getIsbns(),
            ];
        }
        return ['source' => Book::SOURCE_HARDCOVER, 'books' => $out, 'exhausted' => true];
    }
}
