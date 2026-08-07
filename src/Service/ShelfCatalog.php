<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\BookSectionEntry;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for the "shelves" the home page renders as carousels and the
 * browse page can render as a full, paginated grid (`/browse?shelf=<slug>`).
 *
 * Each shelf declares where its books come from:
 *  - TYPE_LIBRARY   — the local library (downloaded books), newest first.
 *  - TYPE_SECTION   — a `(source, section)` shelf populated by the metadata sync
 *                     ({@see BookSectionEntry}), ordered by the stored rank.
 *  - TYPE_TRENDING  — the upstream trending feed. Browse already serves this as its default
 *                     mode from a cached pool, so this shelf carries no DB query; it just maps
 *                     onto BrowseController's existing trending path (no new upstream calls).
 *
 * Card shaping lives here too, so home carousels and browse grid tiles are built from
 * exactly the same rules.
 */
final class ShelfCatalog
{
    public const TYPE_LIBRARY  = 'library';
    public const TYPE_SECTION  = 'section';
    public const TYPE_TRENDING = 'trending';

    public const SHELF_RECENT       = 'recent';
    public const SHELF_TRENDING     = 'trending';
    public const SHELF_NEW_RELEASES = 'new-releases';
    public const SHELF_UPCOMING     = 'upcoming';
    public const SHELF_STAFF_PICKS  = 'staff-picks';

    /**
     * @var array<string, array{label: string, type: string, source: ?string, section: ?string}>
     */
    private const SHELVES = [
        self::SHELF_RECENT => [
            'label' => 'Recently Added', 'type' => self::TYPE_LIBRARY, 'source' => null, 'section' => null,
        ],
        self::SHELF_TRENDING => [
            'label' => 'Trending', 'type' => self::TYPE_TRENDING, 'source' => null, 'section' => null,
        ],
        self::SHELF_NEW_RELEASES => [
            'label' => 'New Releases', 'type' => self::TYPE_SECTION,
            'source' => Book::SOURCE_HARDCOVER, 'section' => BookSectionEntry::SECTION_NEW_RELEASES,
        ],
        self::SHELF_UPCOMING => [
            'label' => 'Upcoming', 'type' => self::TYPE_SECTION,
            'source' => Book::SOURCE_HARDCOVER, 'section' => BookSectionEntry::SECTION_UPCOMING,
        ],
        self::SHELF_STAFF_PICKS => [
            'label' => 'Staff Picks', 'type' => self::TYPE_SECTION,
            'source' => Book::SOURCE_HARDCOVER, 'section' => BookSectionEntry::SECTION_STAFF_PICKS,
        ],
    ];

    public function __construct(
        private readonly CoverCache $covers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Resolve a `?shelf=` slug. Unknown / missing slugs return null so callers fall back to
     * the default browse experience instead of erroring.
     *
     * @return array{slug: string, label: string, type: string, source: ?string, section: ?string}|null
     */
    public static function resolve(?string $slug): ?array
    {
        if ($slug === null) {
            return null;
        }
        $slug = strtolower(trim($slug));
        if (!isset(self::SHELVES[$slug])) {
            return null;
        }
        return ['slug' => $slug] + self::SHELVES[$slug];
    }

    public static function labelFor(string $slug): ?string
    {
        return self::SHELVES[$slug]['label'] ?? null;
    }

    /**
     * A page of books for a DB-backed shelf. Trending is upstream-backed and never reaches
     * here — {@see BrowseController} keeps serving it from its cached pool.
     *
     * `$sort` mirrors the browse sort select: anything other than title/author keeps the
     * shelf's natural order (recency for the library shelf, stored rank for section shelves).
     *
     * @return array{books: list<Book>, has_more: bool}
     */
    public function page(string $slug, int $offset, int $limit, string $sort = 'trending', string $dir = 'ASC'): array
    {
        $shelf = self::resolve($slug);
        if ($shelf === null || $shelf['type'] === self::TYPE_TRENDING) {
            return ['books' => [], 'has_more' => false];
        }

        $offset = max(0, $offset);
        $limit = max(1, $limit);
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        $qb = $this->em->getRepository(Book::class)->createQueryBuilder('b');

        if ($shelf['type'] === self::TYPE_LIBRARY) {
            // Same predicate as BookRepository::findRecentlyAdded — the home shelf's source.
            $qb->select('b', 'COALESCE(b.addedAt, b.firstSeenAt) AS HIDDEN sort_at')
                ->where('b.removedAt IS NULL')
                ->andWhere('b.downloaded = true');
            $natural = static function () use ($qb, $dir): void {
                // Natural order is newest-first; "asc" (the browse default) keeps that.
                $qb->orderBy('sort_at', $dir === 'DESC' ? 'ASC' : 'DESC')
                    ->addOrderBy('b.id', $dir === 'DESC' ? 'ASC' : 'DESC');
            };
        } else {
            $qb->innerJoin(BookSectionEntry::class, 'e', 'WITH', 'e.book = b')
                ->where('e.source = :source')
                ->andWhere('e.section = :section')
                ->setParameter('source', $shelf['source'])
                ->setParameter('section', $shelf['section']);
            $natural = static function () use ($qb, $dir): void {
                $qb->orderBy('e.rank', $dir)->addOrderBy('b.id', $dir);
            };
        }

        if ($sort === 'title' || $sort === 'author') {
            $qb->addSelect(sprintf('LOWER(b.%s) AS HIDDEN sort_key', $sort))
                ->orderBy('sort_key', $dir)
                ->addOrderBy('b.id', $dir);
        } else {
            $natural();
        }

        // Fetch one extra row to answer has_more without a second count query.
        /** @var list<Book> $rows */
        $rows = $qb->setFirstResult($offset)->setMaxResults($limit + 1)->getQuery()->getResult();
        $hasMore = count($rows) > $limit;

        return ['books' => array_slice($rows, 0, $limit), 'has_more' => $hasMore];
    }

    /**
     * Shape books into the card payload both the home carousels and the browse grid render.
     *
     * @param list<Book> $books
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @return list<array<string, mixed>>
     */
    public function toCards(array $books, array $libraryIsbns, array $libraryKeys, array $statusMaps): array
    {
        $out = [];
        foreach ($books as $book) {
            $out[] = $this->toCard($book, $libraryIsbns, $libraryKeys, $statusMaps);
        }
        return $out;
    }

    /**
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @return array<string, mixed>
     */
    public function toCard(Book $book, array $libraryIsbns, array $libraryKeys, array $statusMaps): array
    {
        $rawTitle = $book->getTitle();
        $author = $book->getAuthor();

        $title = $rawTitle;
        if ($book->getSeries() !== null && $book->getSeriesIndex() !== null) {
            $title = sprintf('%s (%s #%s)', $rawTitle, $book->getSeries(), $book->getSeriesIndex());
        }

        // Walk every edition's ISBN so a trending entry whose first ISBN happens to be the
        // German paperback still flags as "downloaded" when the user owns the US hardcover.
        $allIsbns = $book->getIsbns();
        if ($allIsbns === [] && $book->getIsbn() !== null) {
            $allIsbns = [$book->getIsbn()];
        }

        $downloaded = $book->isDownloaded();
        if (!$downloaded) {
            foreach ($allIsbns as $candidate) {
                if (isset($libraryIsbns[$candidate])) {
                    $downloaded = true;
                    break;
                }
            }
        }
        $taKey = BookRepository::normalizeTitleAuthor($rawTitle, $author);
        if (!$downloaded && $taKey !== null && isset($libraryKeys[$taKey])) {
            $downloaded = true;
        }

        $requestStatus = null;
        foreach ($allIsbns as $candidate) {
            if (isset($statusMaps['isbns'][$candidate])) {
                $requestStatus = $statusMaps['isbns'][$candidate];
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

        $isGrimmory = $book->getSource() === Book::SOURCE_GRIMMORY;

        return [
            'title' => $title,
            'author' => $author,
            'downloaded' => $downloaded,
            'request_status' => $requestStatus,
            'cover_url' => $this->coverProxyFor($book),
            'external_url' => $book->getExternalUrl(),
            'meta_source' => $isGrimmory ? null : $book->getSource(),
            'meta_external_id' => $isGrimmory ? null : $book->getExternalId(),
            'meta_id' => $book->getId(),
            'audiobook' => $book->isAudiobookAvailable(),
        ];
    }

    public function coverProxyFor(Book $book): ?string
    {
        if ($book->getSource() === Book::SOURCE_GRIMMORY) {
            return $this->covers->proxyUrlForKomga($book->getExternalId());
        }
        $remote = $book->getCoverUrl();
        return $remote !== null && $remote !== '' ? $this->covers->proxyUrlForRemote($remote) : null;
    }
}
