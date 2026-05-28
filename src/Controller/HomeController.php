<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\BookSectionEntry;
use App\Entity\Integration;
use App\Entity\User;
use App\Message\TouchBooksSeen;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * Editorial-controlled tiles; gradients are inline styles, not theme-derived.
     *
     * @var list<array{label: string, slug: string, background: string}>
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
        ['label' => 'Non-Fiction',     'slug' => 'non-fiction',        'background' => 'linear-gradient(135deg, #475569, #334155)'],
        ['label' => 'Biography',       'slug' => 'biography',          'background' => 'linear-gradient(135deg, #b45309, #92400e)'],
        ['label' => 'Graphic Novels',  'slug' => 'graphic-novels',     'background' => 'linear-gradient(135deg, #db2777, #f59e0b)'],
        ['label' => 'Manga',           'slug' => 'manga',              'background' => 'linear-gradient(135deg, #be123c, #1f2937)'],
    ];

    private const HOME_SHELF_LIMIT = 25;

    public function __construct(
        private readonly CoverCache $covers,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/', name: 'home')]
    public function index(
        BookRepository $books,
        AuthorRepository $authors,
        IntegrationRepository $integrations,
        BookRequestRepository $requests,
    ): Response {
        $user = $this->getUser();
        $requestStatusMaps = $user instanceof User
            ? $requests->statusMapsForUser($user)
            : ['isbns' => [], 'titleAuthor' => []];

        $recentlyAddedBooks = $books->findRecentlyAdded(15);
        $recentlyAdded = array_map(
            fn (Book $b) => $this->bookToCard($b, $requestStatusMaps),
            $recentlyAddedBooks,
        );

        $libraryIsbns = $books->downloadedIsbns();
        $libraryKeys = $books->downloadedTitleAuthorKeys();

        $hardcover = $integrations->findByKind(Integration::KIND_HARDCOVER);
        $openLibrary = $integrations->findByKind(Integration::KIND_OPENLIBRARY);

        [$trendingBooks, $trendingSubtitle, $trendingEmpty] = $this->loadTrending($books, $hardcover, $openLibrary);
        $trendingItems = $this->booksToCards($trendingBooks, $hardcover ?? $openLibrary, $libraryIsbns, $libraryKeys, $requestStatusMaps);

        $newReleasesBooks = $this->shelfFromHardcover($books, $hardcover, BookSectionEntry::SECTION_NEW_RELEASES);
        $upcomingBooks    = $this->shelfFromHardcover($books, $hardcover, BookSectionEntry::SECTION_UPCOMING);
        $staffPicksBooks  = $this->shelfFromHardcover($books, $hardcover, BookSectionEntry::SECTION_STAFF_PICKS);

        $newReleases = $this->booksToCards($newReleasesBooks, $hardcover, $libraryIsbns, $libraryKeys, $requestStatusMaps);
        $upcoming    = $this->booksToCards($upcomingBooks, $hardcover, $libraryIsbns, $libraryKeys, $requestStatusMaps);
        $staffPicks  = $this->booksToCards($staffPicksBooks, $hardcover, $libraryIsbns, $libraryKeys, $requestStatusMaps);

        $authorsList = $this->popularAuthorsFromHardcover($authors, $hardcover);

        $genres = self::BROWSE_GENRES;

        $hardcoverEmpty = $hardcover !== null && $hardcover->isEnabled()
            ? 'Waiting for data to populate. Either wait for the next automatic refresh, or request one now from Settings → Metadata.'
            : 'Enable Hardcover in Settings → Metadata to populate this row.';

        $this->dispatchTouch(array_merge(
            $recentlyAddedBooks,
            $trendingBooks,
            $newReleasesBooks,
            $upcomingBooks,
            $staffPicksBooks,
        ));

        return $this->render('home/index.html.twig', [
            'sections' => [
                ['title' => 'Recently Added', 'items' => $recentlyAdded],
                ['title' => 'Trending', 'items' => $trendingItems,
                 'empty_message' => $trendingEmpty],
                ['title' => 'New Releases', 'items' => $newReleases, 'empty_message' => $hardcoverEmpty],
                ['title' => 'Upcoming', 'items' => $upcoming, 'empty_message' => $hardcoverEmpty],
                ['title' => 'Browse by Genre', 'items' => $genres, 'kind' => 'genre'],
                ['title' => 'Staff Picks', 'items' => $staffPicks, 'empty_message' => $hardcoverEmpty],
                ['title' => 'Popular Authors', 'items' => $authorsList, 'kind' => 'author', 'empty_message' => $hardcoverEmpty],
                ['title' => 'Recent Requests', 'items' => [], 'kind' => 'request', 'empty_message' => 'Book requests will appear here once the request flow is available.'],
            ],
        ]);
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
     * @param list<Book> $books
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $requestStatusMaps
     * @return list<array<string, mixed>>
     */
    private function booksToCards(array $books, ?Integration $integration, array $libraryIsbns, array $libraryKeys, array $requestStatusMaps): array
    {
        $out = [];
        foreach ($books as $book) {
            $title = $book->getTitle();
            $author = $book->getAuthor();
            // Walk every edition's ISBN so a Hardcover trending entry whose first ISBN happens
            // to be the German paperback still flags as "downloaded" when the user owns the US
            // hardcover. Mirrors BrowseController::normalizeCards.
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
            $taKey = BookRepository::normalizeTitleAuthor($title, $author);
            if (!$downloaded && $taKey !== null && isset($libraryKeys[$taKey])) {
                $downloaded = true;
            }

            $requestStatus = null;
            foreach ($allIsbns as $candidate) {
                if (isset($requestStatusMaps['isbns'][$candidate])) {
                    $requestStatus = $requestStatusMaps['isbns'][$candidate];
                    break;
                }
            }
            if ($requestStatus === null && $taKey !== null && isset($requestStatusMaps['titleAuthor'][$taKey])) {
                $requestStatus = $requestStatusMaps['titleAuthor'][$taKey];
            }
            if ($requestStatus === 'available') {
                $downloaded = true;
                $requestStatus = null;
            }

            $externalUrl = $book->getExternalUrl();
            $coverProxy = $this->coverProxyFor($book);
            $out[] = [
                'title' => $title,
                'author' => $author,
                'downloaded' => $downloaded,
                'request_status' => $requestStatus,
                'cover_url' => $coverProxy,
                'external_url' => $externalUrl,
                'meta_source' => $book->getSource() === Book::SOURCE_GRIMMORY ? null : $book->getSource(),
                'meta_external_id' => $book->getSource() === Book::SOURCE_GRIMMORY ? null : $book->getExternalId(),
                'meta_id' => $book->getId(),
            ];
        }
        return $out;
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
     * @param array{isbns: array<string, string>, titleAuthor: array<string, string>} $requestStatusMaps
     * @return array{title: string, author: ?string, downloaded: bool, request_status: ?string, cover_url: string, meta_id: ?int}
     */
    private function bookToCard(Book $book, array $requestStatusMaps): array
    {
        $title = $book->getTitle();
        if ($book->getSeries() !== null && $book->getSeriesIndex() !== null) {
            $title = sprintf('%s (%s #%s)', $title, $book->getSeries(), $book->getSeriesIndex());
        }
        $downloaded = $book->isDownloaded();
        $requestStatus = null;
        $isbn = $book->getIsbn();
        if (is_string($isbn) && isset($requestStatusMaps['isbns'][$isbn])) {
            $requestStatus = $requestStatusMaps['isbns'][$isbn];
        } else {
            $taKey = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());
            if ($taKey !== null && isset($requestStatusMaps['titleAuthor'][$taKey])) {
                $requestStatus = $requestStatusMaps['titleAuthor'][$taKey];
            }
        }
        if ($requestStatus === 'available') {
            $downloaded = true;
            $requestStatus = null;
        }
        return [
            'title' => $title,
            'author' => $book->getAuthor(),
            'downloaded' => $downloaded,
            'request_status' => $requestStatus,
            'cover_url' => $this->covers->proxyUrlForKomga($book->getExternalId()),
            'meta_id' => $book->getId(),
        ];
    }

    private function coverProxyFor(Book $book): ?string
    {
        if ($book->getSource() === Book::SOURCE_GRIMMORY) {
            return $this->covers->proxyUrlForKomga($book->getExternalId());
        }
        $remote = $book->getCoverUrl();
        return $remote !== null && $remote !== '' ? $this->covers->proxyUrlForRemote($remote) : null;
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
