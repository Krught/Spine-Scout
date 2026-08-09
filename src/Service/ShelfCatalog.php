<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\BookSectionEntry;
use App\Entity\FreeleechItem;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\FreeleechItemRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
 *  - TYPE_FREELEECH — the MyAnonamouse freeleech set ({@see FreeleechItem}). Conditional: it
 *                     only exists when the integration is on, the browse shelf is enabled, and
 *                     the viewer holds ROLE_VIEW_FREELEECH, so it is resolved through the
 *                     instance {@see resolveVisible()} rather than the static catalog.
 *
 * Card shaping lives here too, so home carousels and browse grid tiles are built from
 * exactly the same rules.
 */
final class ShelfCatalog
{
    public const TYPE_LIBRARY   = 'library';
    public const TYPE_SECTION   = 'section';
    public const TYPE_TRENDING  = 'trending';
    public const TYPE_FREELEECH = 'freeleech';

    public const SHELF_RECENT       = 'recent';
    public const SHELF_TRENDING     = 'trending';
    public const SHELF_NEW_RELEASES = 'new-releases';
    public const SHELF_UPCOMING     = 'upcoming';
    public const SHELF_STAFF_PICKS  = 'staff-picks';
    public const SHELF_FREELEECH    = 'freeleech';

    public const FREELEECH_LABEL = 'Freeleech';

    /** @var array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>} */
    private const NO_FREELEECH = ['ids' => [], 'isbns' => [], 'keys' => []];

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
        private readonly FreeleechItemRepository $freeleechItems,
        private readonly IntegrationRepository $integrations,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
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
        if ($slug === self::SHELF_FREELEECH) {
            return self::FREELEECH_LABEL;
        }
        return self::SHELVES[$slug]['label'] ?? null;
    }

    /**
     * Slug resolution for the *current viewer*. Identical to {@see resolve()} except that the
     * conditional freeleech shelf is folded in: it exists only while the integration is on,
     * `showBrowseShelf` is set, and the viewer holds ROLE_VIEW_FREELEECH. Otherwise the slug
     * is simply unknown and callers fall back to the default browse experience.
     *
     * @return array{slug: string, label: string, type: string, source: ?string, section: ?string}|null
     */
    public function resolveVisible(?string $slug): ?array
    {
        if ($slug !== null && strtolower(trim($slug)) === self::SHELF_FREELEECH) {
            if (!$this->freeleechShelfVisible()) {
                return null;
            }
            return [
                'slug' => self::SHELF_FREELEECH, 'label' => self::FREELEECH_LABEL,
                'type' => self::TYPE_FREELEECH, 'source' => null, 'section' => null,
            ];
        }

        return self::resolve($slug);
    }

    public function freeleechShelfVisible(): bool
    {
        $config = $this->integrations->getMyAnonamouseConfig();

        return $config->enabled
            && $config->showBrowseShelf
            && $this->security->isGranted(User::ROLE_VIEW_FREELEECH);
    }

    public function freeleechRowVisible(): bool
    {
        $config = $this->integrations->getMyAnonamouseConfig();

        return $config->enabled
            && $config->showOnHomepage
            && $this->security->isGranted(User::ROLE_VIEW_FREELEECH);
    }

    /**
     * Whether VIP-only picks count as freeleech anywhere in the UI. There is no user-facing
     * toggle: the operator's fetchVipFreeleech setting decides both what gets pulled and what
     * gets shown, so with it off the rows are neither fetched nor kept.
     */
    public function freeleechIncludeVip(): bool
    {
        return $this->integrations->getMyAnonamouseConfig()->fetchVipFreeleech;
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
     * The freeleech badge index for one viewer: every book that is freeleech right now, keyed
     * the three ways card stamping already resolves a book (internal id, any edition ISBN,
     * normalized title+author). The value is the VIP-only flag, so a card can pair the coin
     * with the "VIP" marker. Empty — and free of queries — for viewers without the role or
     * while the integration is off.
     *
     * @return array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>}
     */
    public function freeleechBadges(bool $includeVip): array
    {
        if (!$this->security->isGranted(User::ROLE_VIEW_FREELEECH)) {
            return self::NO_FREELEECH;
        }
        if (!$this->integrations->getMyAnonamouseConfig()->enabled) {
            return self::NO_FREELEECH;
        }

        $ids = $this->freeleechItems->freeleechBookIds($includeVip);
        if ($ids === []) {
            return self::NO_FREELEECH;
        }

        // With VIP items included, the regular set tells us which of them are VIP-*only*.
        $vipOnly = [];
        if ($includeVip) {
            $regular = array_fill_keys($this->freeleechItems->freeleechBookIds(false), true);
            foreach ($ids as $id) {
                if (!isset($regular[$id])) {
                    $vipOnly[$id] = true;
                }
            }
        }

        $out = self::NO_FREELEECH;
        /** @var list<Book> $books */
        $books = $this->em->getRepository(Book::class)->findBy(['id' => $ids]);
        foreach ($books as $book) {
            $id = (int) $book->getId();
            $vip = isset($vipOnly[$id]);
            $out['ids'][$id] = $vip;

            $isbns = $book->getIsbns();
            if ($isbns === [] && $book->getIsbn() !== null) {
                $isbns = [$book->getIsbn()];
            }
            foreach ($isbns as $isbn) {
                $key = (string) $isbn;
                // A regular freeleech copy outranks a VIP-only one on a shared key.
                if (!isset($out['isbns'][$key]) || $out['isbns'][$key]) {
                    $out['isbns'][$key] = $vip;
                }
            }

            $taKey = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());
            if ($taKey !== null && (!isset($out['keys'][$taKey]) || $out['keys'][$taKey])) {
                $out['keys'][$taKey] = $vip;
            }
        }

        return $out;
    }

    /**
     * Resolve one card against a {@see freeleechBadges()} index, by the same three keys the
     * owned/requested stamping uses.
     *
     * @param array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>} $badges
     * @param list<string|int> $isbns
     * @return array{0: bool, 1: bool} [is freeleech, is VIP-only]
     */
    public static function freeleechFlags(array $badges, ?int $bookId, array $isbns, ?string $taKey): array
    {
        if ($bookId !== null && isset($badges['ids'][$bookId])) {
            return [true, $badges['ids'][$bookId]];
        }
        foreach ($isbns as $isbn) {
            $key = (string) $isbn;
            if (isset($badges['isbns'][$key])) {
                return [true, $badges['isbns'][$key]];
            }
        }
        if ($taKey !== null && isset($badges['keys'][$taKey])) {
            return [true, $badges['keys'][$taKey]];
        }

        return [false, false];
    }

    /**
     * Shape a page of freeleech items into cards. Resolved items run through the ordinary
     * {@see toCard()} shaping — they are plain catalog books that happen to be free — while
     * unresolved ones fall back to the MAM strings and the locally cached MAM thumbnail.
     *
     * @param list<FreeleechItem> $items
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @return list<array<string, mixed>>
     */
    public function freeleechCards(array $items, array $libraryIsbns, array $libraryKeys, array $statusMaps): array
    {
        $out = [];
        foreach ($items as $item) {
            $book = $item->getBook();
            if ($item->isResolved() && $book !== null) {
                $card = $this->toCard($book, $libraryIsbns, $libraryKeys, $statusMaps);
            } else {
                $card = $this->freeleechFallbackCard($item, $libraryKeys);
            }
            $card['freeleech'] = true;
            $card['freeleech_vip'] = $item->isFlVip() && !$item->isFree();
            $out[] = $card;
        }

        return $out;
    }

    /**
     * A card for an item Hardcover could not resolve: same keys as every other card, but no
     * modal identifiers at all, so the templates render it as a plain, unclickable tile.
     *
     * @param array<string, true> $libraryKeys
     * @return array<string, mixed>
     */
    private function freeleechFallbackCard(FreeleechItem $item, array $libraryKeys): array
    {
        $author = implode(', ', $item->getAuthors());
        $narrator = implode(', ', $item->getNarrators());
        $taKey = BookRepository::normalizeTitleAuthor($item->getTitle(), $author !== '' ? $author : null);

        return [
            'title' => $item->getTitle(),
            'author' => $author !== '' ? $author : null,
            'narrator' => $narrator !== '' ? $narrator : null,
            'downloaded' => $taKey !== null && isset($libraryKeys[$taKey]),
            'request_status' => null,
            'cover_url' => $item->getThumbnailUrl() !== null
                ? $this->urls->generate('freeleech_cover', ['id' => $item->getId()])
                : null,
            'external_url' => null,
            'meta_source' => null,
            'meta_external_id' => null,
            'meta_id' => null,
            'audiobook' => $item->isAudiobook(),
        ];
    }

    /**
     * Shape books into the card payload both the home carousels and the browse grid render.
     *
     * @param list<Book> $books
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @param array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>} $freeleechBadges
     * @return list<array<string, mixed>>
     */
    public function toCards(array $books, array $libraryIsbns, array $libraryKeys, array $statusMaps, array $freeleechBadges = self::NO_FREELEECH): array
    {
        $out = [];
        foreach ($books as $book) {
            $out[] = $this->toCard($book, $libraryIsbns, $libraryKeys, $statusMaps, $freeleechBadges);
        }
        return $out;
    }

    /**
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $statusMaps
     * @param array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>} $freeleechBadges
     * @return array<string, mixed>
     */
    public function toCard(Book $book, array $libraryIsbns, array $libraryKeys, array $statusMaps, array $freeleechBadges = self::NO_FREELEECH): array
    {
        $rawTitle = $book->getTitle();
        $author = $book->getAuthor();

        $title = $book->displayTitle();

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

        [$freeleech, $freeleechVip] = self::freeleechFlags($freeleechBadges, $book->getId(), $allIsbns, $taKey);

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
            'freeleech' => $freeleech,
            'freeleech_vip' => $freeleechVip,
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
