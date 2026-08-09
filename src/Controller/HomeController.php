<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Message\TouchBooksSeen;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\FreeleechItemRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use App\Service\ShelfCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class HomeController extends AbstractController
{
    /**
     * Editorial-controlled tiles; gradients are inline styles, not theme-derived.
     *
     * `query` (optional) overrides the term sent to the genre search when the displayed `label`
     * does not match Hardcover's tag vocabulary (e.g. "Non-Fiction" vs Hardcover's "Nonfiction").
     * When absent, the label is used as the search term.
     *
     * @var list<array{label: string, slug: string, background: string, query?: string}>
     */
    private const BROWSE_GENRES = [
        ['label' => 'Fantasy',         'slug' => 'fantasy',            'background' => 'linear-gradient(135deg, #2f5a3f, #1d3a28)'],
        ['label' => 'Science Fiction', 'slug' => 'science-fiction',    'background' => 'linear-gradient(135deg, #1f3a8a, #4338ca)'],
        ['label' => 'Mystery',         'slug' => 'mystery',            'background' => 'linear-gradient(135deg, #1f2937, #111827)'],
        ['label' => 'Thriller',        'slug' => 'thriller',           'background' => 'linear-gradient(135deg, #422006, #1c1917)'],
        ['label' => 'Romance',         'slug' => 'romance',            'background' => 'linear-gradient(135deg, #9d174d, #be185d)'],
        ['label' => 'Horror',          'slug' => 'horror',             'background' => 'linear-gradient(135deg, #7f1d1d, #450a0a)'],
        ['label' => 'Historical',      'slug' => 'historical-fiction', 'background' => 'linear-gradient(135deg, #92400e, #78350f)'],
        ['label' => 'Young Adult',     'slug' => 'young-adult',        'background' => 'linear-gradient(135deg, #7c3aed, #c026d3)'],
        ['label' => 'Non-Fiction',     'slug' => 'non-fiction',        'background' => 'linear-gradient(135deg, #475569, #334155)', 'query' => 'Nonfiction'],
        ['label' => 'Self-Help',       'slug' => 'self-help',          'background' => 'linear-gradient(135deg, #0d9488, #0f766e)'],
        ['label' => 'Biography',       'slug' => 'biography',          'background' => 'linear-gradient(135deg, #b45309, #92400e)'],
        ['label' => 'Graphic Novels',  'slug' => 'graphic-novels',     'background' => 'linear-gradient(135deg, #db2777, #f59e0b)'],
        ['label' => 'Manga',           'slug' => 'manga',              'background' => 'linear-gradient(135deg, #be123c, #1f2937)'],
    ];

    private const HOME_SHELF_LIMIT = 25;
    private const FREELEECH_ROW_LIMIT = 20;

    /**
     * Stable section keys in their out-of-the-box order. A stored per-user order is
     * merged against this list, so a key added in a future release lands at its
     * default position for users whose saved order predates it.
     *
     * @var list<string>
     */
    public const DEFAULT_SECTION_ORDER = [
        'recent',
        'trending',
        'new_releases',
        'upcoming',
        'genres',
        'staff_picks',
        'authors',
        'requests',
        'freeleech',
    ];

    /** @var array<string, string> */
    public const SECTION_LABELS = [
        'recent'       => 'Recently Added',
        'trending'     => 'Trending',
        'new_releases' => 'New Releases',
        'upcoming'     => 'Upcoming',
        'genres'       => 'Browse by Genre',
        'staff_picks'  => 'Staff Picks',
        'authors'      => 'Popular Authors',
        'requests'     => 'Recent Requests',
        'freeleech'    => ShelfCatalog::FREELEECH_LABEL,
    ];

    private const SECTIONS_CSRF_ID = 'home_sections';

    public function __construct(
        private readonly CoverCache $covers,
        private readonly MessageBusInterface $bus,
        private readonly ShelfCatalog $shelves,
    ) {
    }

    #[Route('/', name: 'home')]
    public function index(
        BookRepository $books,
        AuthorRepository $authors,
        IntegrationRepository $integrations,
        BookRequestRepository $requests,
        FreeleechItemRepository $freeleech,
    ): Response {
        $user = $this->getUser();

        // Availability is decided before any preference is consulted, so a stored key can
        // never resurrect a row the integration or the viewer's capabilities rule out.
        $available = [];
        foreach (self::DEFAULT_SECTION_ORDER as $key) {
            if ($key === 'freeleech' && !$this->shelves->freeleechRowVisible()) {
                continue;
            }
            $available[] = $key;
        }

        $order = self::mergeOrder($available, $user instanceof User ? $user->getHomeSectionsOrder() : []);
        $hiddenKeys = $user instanceof User
            ? array_values(array_intersect($user->getHiddenHomeSections(), $available))
            : [];
        $visibleKeys = array_values(array_filter($order, static fn (string $k) => !in_array($k, $hiddenKeys, true)));

        // Page-wide lookups every book row shares (owned / requested / freeleech coin), paid
        // for once and only if a row that needs them actually renders.
        $shared = null;
        $sharedData = function () use (&$shared, $books, $requests, $user): array {
            if ($shared === null) {
                $includeVip = $this->shelves->freeleechIncludeVip();
                $shared = [
                    'isbns'       => $books->downloadedIsbns(),
                    'keys'        => $books->downloadedTitleAuthorKeys(),
                    'status'      => $user instanceof User
                        ? $requests->statusMapsForUser($user)
                        : ['isbns' => [], 'titleAuthor' => []],
                    'badges'      => $this->shelves->freeleechBadges($includeVip),
                    'include_vip' => $includeVip,
                ];
            }

            return $shared;
        };

        $loaded = [];
        $integration = function (string $kind) use (&$loaded, $integrations): ?Integration {
            if (!array_key_exists($kind, $loaded)) {
                $loaded[$kind] = $integrations->findByKind($kind);
            }

            return $loaded[$kind];
        };

        $hardcoverEmpty = function () use ($integration): string {
            $hardcover = $integration(Integration::KIND_HARDCOVER);

            return $hardcover !== null && $hardcover->isEnabled()
                ? 'Waiting for data to populate. Either wait for the next automatic refresh, or request one now from Settings → Metadata.'
                : 'Enable Hardcover in Settings → Metadata to populate this row.';
        };

        $touched = [];
        $cards = function (array $rows) use ($sharedData, &$touched): array {
            foreach ($rows as $row) {
                $touched[] = $row;
            }
            $shared = $sharedData();

            // Card shaping is shared with the browse grid so /browse?shelf=<slug> renders the
            // same tiles as the home carousel it came from.
            return $this->shelves->toCards($rows, $shared['isbns'], $shared['keys'], $shared['status'], $shared['badges']);
        };

        // One builder per key, invoked only for the rows this viewer actually sees: a hidden
        // row costs nothing because its queries live inside the closure body.
        //
        // `more_shelf` drives the row's "See all" link: it targets /browse?shelf=<slug>,
        // which renders the same dataset as a paginated grid (see ShelfCatalog). Rows
        // without a browsable dataset (genres, authors) simply omit it and render no
        // "See all"; Recent Requests points at the requests page instead (`more_route`).
        $builders = [
            'recent' => fn (): array => [
                'items' => $cards($books->findRecentlyAdded(15)),
                'more_shelf' => ShelfCatalog::SHELF_RECENT,
            ],
            'trending' => function () use ($books, $integration, $cards): array {
                [$rows, , $empty] = $this->loadTrending(
                    $books,
                    $integration(Integration::KIND_HARDCOVER),
                    $integration(Integration::KIND_OPENLIBRARY),
                );

                return ['items' => $cards($rows), 'empty_message' => $empty, 'more_shelf' => ShelfCatalog::SHELF_TRENDING];
            },
            'new_releases' => fn (): array => [
                'items' => $cards($this->shelfFromHardcover($books, $integration(Integration::KIND_HARDCOVER), BookSectionEntry::SECTION_NEW_RELEASES)),
                'empty_message' => $hardcoverEmpty(),
                'more_shelf' => ShelfCatalog::SHELF_NEW_RELEASES,
            ],
            'upcoming' => fn (): array => [
                'items' => $cards($this->shelfFromHardcover($books, $integration(Integration::KIND_HARDCOVER), BookSectionEntry::SECTION_UPCOMING)),
                'empty_message' => $hardcoverEmpty(),
                'more_shelf' => ShelfCatalog::SHELF_UPCOMING,
            ],
            'genres' => fn (): array => ['items' => self::BROWSE_GENRES, 'kind' => 'genre'],
            'staff_picks' => fn (): array => [
                'items' => $cards($this->shelfFromHardcover($books, $integration(Integration::KIND_HARDCOVER), BookSectionEntry::SECTION_STAFF_PICKS)),
                'empty_message' => $hardcoverEmpty(),
                'more_shelf' => ShelfCatalog::SHELF_STAFF_PICKS,
            ],
            'authors' => fn (): array => [
                'items' => $this->popularAuthorsFromHardcover($authors, $integration(Integration::KIND_HARDCOVER)),
                'kind' => 'author',
                'empty_message' => $hardcoverEmpty(),
            ],
            'requests' => fn (): array => [
                'items' => array_map(
                    fn (BookRequest $r) => $this->requestToCard($r, $sharedData()['badges']),
                    $requests->findRecent(15),
                ),
                'kind' => 'request',
                'more_route' => 'requests',
                'empty_message' => 'Book requests will appear here once someone requests a book.',
            ],
            'freeleech' => function () use ($freeleech, $sharedData): array {
                $shared = $sharedData();
                $row = [
                    'items' => $this->shelves->freeleechCards(
                        $freeleech->pageForBrowse(null, null, $shared['include_vip'], 'added', 'desc', 0, self::FREELEECH_ROW_LIMIT),
                        $shared['isbns'],
                        $shared['keys'],
                        $shared['status'],
                    ),
                    'empty_message' => 'Nothing is freeleech on MyAnonamouse right now, or the first refresh has not run yet.',
                ];
                // "See all" only exists when the browse shelf is exposed too.
                if ($this->shelves->freeleechShelfVisible()) {
                    $row['more_shelf'] = ShelfCatalog::SHELF_FREELEECH;
                }

                return $row;
            },
        ];

        $sections = [];
        foreach ($visibleKeys as $key) {
            $sections[] = ['key' => $key, 'title' => self::SECTION_LABELS[$key]] + $builders[$key]();
        }

        $this->dispatchTouch($touched);

        $customize = [];
        foreach ($order as $key) {
            $customize[] = [
                'key' => $key,
                'label' => self::SECTION_LABELS[$key],
                'visible' => !in_array($key, $hiddenKeys, true),
            ];
        }

        return $this->render('home/index.html.twig', [
            'sections' => $sections,
            'customize_sections' => $customize,
            'customize_csrf_id' => self::SECTIONS_CSRF_ID,
        ]);
    }

    /**
     * The user's saved order, filtered to what is available, with every remaining
     * available key spliced back in at its DEFAULT_SECTION_ORDER position (right after
     * the nearest preceding default key that survived) — so unknown-to-the-user keys
     * appear where a fresh install would put them instead of being appended blindly.
     *
     * @param list<string> $available
     * @param list<string> $saved
     * @return list<string>
     */
    private static function mergeOrder(array $available, array $saved): array
    {
        $out = [];
        foreach ($saved as $key) {
            if (in_array($key, $available, true) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        foreach (self::DEFAULT_SECTION_ORDER as $i => $key) {
            if (!in_array($key, $available, true) || in_array($key, $out, true)) {
                continue;
            }
            $at = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $found = array_search(self::DEFAULT_SECTION_ORDER[$j], $out, true);
                if ($found !== false) {
                    $at = $found + 1;
                    break;
                }
            }
            array_splice($out, $at, 0, [$key]);
        }

        return $out;
    }

    /**
     * Persists the viewer's Discover layout. Accepts JSON or form-encoded
     * `order` / `hidden` key lists (unknown keys are dropped) plus `_token`; a truthy
     * `reset` clears the stored preference back to the shipped defaults.
     */
    #[Route('/home/sections', name: 'home_sections', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveSections(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : $request->request->all();

        if (!$this->isCsrfTokenValid(self::SECTIONS_CSRF_ID, (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        if (($payload['reset'] ?? false) === true || ($payload['reset'] ?? '') === '1') {
            $user->setHomeSections(null);
        } else {
            $user->setHomeSections([
                'order'  => self::knownKeys($payload['order'] ?? null),
                'hidden' => self::knownKeys($payload['hidden'] ?? null),
            ]);
        }

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** @return list<string> */
    private static function knownKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && in_array($value, self::DEFAULT_SECTION_ORDER, true) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<Book>, 1: string, 2: string}
     */
    private function loadTrending(BookRepository $books, ?Integration $hardcover, ?Integration $openLibrary): array
    {
        $hardcoverOn = $hardcover !== null && $hardcover->isEnabled();
        $openLibraryOn = $openLibrary !== null && $openLibrary->isEnabled();
        if ($hardcoverOn) {
            $rows = $books->findBySection(Book::SOURCE_HARDCOVER, BookSectionEntry::SECTION_TRENDING, self::HOME_SHELF_LIMIT);
            if ($rows !== []) {
                return [$rows, 'Trending on Hardcover', ''];
            }
        }
        if ($openLibraryOn) {
            $rows = $books->findBySection(Book::SOURCE_OPENLIBRARY, BookSectionEntry::SECTION_TRENDING, self::HOME_SHELF_LIMIT);
            if ($rows !== []) {
                return [$rows, 'Trending on Open Library', ''];
            }
        }
        $empty = $hardcoverOn || $openLibraryOn
            ? 'Waiting for data to populate. Either wait for the next automatic refresh, or request one now from Settings → Metadata.'
            : 'Enable Hardcover or Open Library in Settings → Metadata to populate this row.';
        return [[], $hardcoverOn ? 'Trending on Hardcover' : ($openLibraryOn ? 'Trending on Open Library' : ''), $empty];
    }

    /**
     * @return list<Book>
     */
    private function shelfFromHardcover(BookRepository $books, ?Integration $hardcover, string $section): array
    {
        if ($hardcover === null || !$hardcover->isEnabled()) {
            return [];
        }
        return $books->findBySection(Book::SOURCE_HARDCOVER, $section, self::HOME_SHELF_LIMIT);
    }

    /**
     * @return list<array{name: string, slug: ?string, image_url: ?string, image_remote_url: ?string, external_url: ?string}>
     */
    private function popularAuthorsFromHardcover(AuthorRepository $authors, ?Integration $hardcover): array
    {
        if ($hardcover === null || !$hardcover->isEnabled()) {
            return [];
        }
        $rows = $authors->findPopular(Author::SOURCE_HARDCOVER, 20);
        $out = [];
        foreach ($rows as $a) {
            $remote = $a->getImageUrl();
            $out[] = [
                'name' => $a->getName(),
                'slug' => $a->getSlug(),
                'image_url' => $remote !== null && $remote !== '' ? $this->covers->proxyUrlForRemote($remote) : null,
                'image_remote_url' => $remote,
                'external_url' => $a->getExternalUrl(),
            ];
        }
        return $out;
    }

    /**
     * Card for the "Recent Requests" shelf. Mirrors the display-status derivation
     * RequestsController uses: AVAILABLE (or the auto-promoted equivalent) renders
     * as in-library; an approved request whose download completed shows the
     * "downloaded" badge; otherwise the stored pending/approved/rejected status.
     *
     * @param array{ids: array<int, bool>, isbns: array<string, bool>, keys: array<string, bool>} $freeleechBadges
     * @return array{title: string, author: ?string, downloaded: bool, request_status: ?string, cover_url: ?string, requester: string, meta_id: ?int, freeleech: bool, freeleech_vip: bool}
     */
    private function requestToCard(BookRequest $request, array $freeleechBadges): array
    {
        $book = $request->getBook();

        $downloaded = false;
        $requestStatus = null;
        if ($request->getStatus() === BookRequest::STATUS_AVAILABLE) {
            $downloaded = true;
        } elseif ($request->getStatus() === BookRequest::STATUS_APPROVED && $request->getDeliveryStatus() === DownloadJob::STATUS_COMPLETE) {
            $requestStatus = 'downloaded';
        } else {
            $requestStatus = $request->getStatus();
        }

        [$freeleech, $freeleechVip] = ShelfCatalog::freeleechFlags($freeleechBadges, $book->getId(), [], null);

        return [
            'title' => $book->getTitle(),
            'author' => $book->getAuthor(),
            'downloaded' => $downloaded,
            'request_status' => $requestStatus,
            'cover_url' => $this->coverProxyFor($book),
            'requester' => $request->getRequestedBy()->getUsername(),
            'meta_id' => $book->getId(),
            'freeleech' => $freeleech,
            'freeleech_vip' => $freeleechVip,
        ];
    }

    private function coverProxyFor(Book $book): ?string
    {
        return $this->shelves->coverProxyFor($book);
    }

    /**
     * @param iterable<Book> $books
     */
    private function dispatchTouch(iterable $books): void
    {
        $ids = [];
        foreach ($books as $b) {
            $id = $b->getId();
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return;
        }
        $this->bus->dispatch(new TouchBooksSeen(array_keys($ids)));
    }

    #[Route('/healthz', name: 'healthz')]
    public function healthz(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
