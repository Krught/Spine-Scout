<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\Integration;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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

    public function __construct(private readonly CoverCache $covers)
    {
    }

    #[Route('/', name: 'home')]
    public function index(BookRepository $books, IntegrationRepository $integrations): Response
    {
        $recentlyAdded = array_map(
            $this->bookToCard(...),
            $books->findRecentlyAdded(15),
        );

        $libraryIsbns = $books->downloadedIsbns();
        $libraryKeys = $books->downloadedTitleAuthorKeys();

        $hardcover = $integrations->findByKind(Integration::KIND_HARDCOVER);
        $openLibrary = $integrations->findByKind(Integration::KIND_OPENLIBRARY);

        [$trendingItems, $trendingSubtitle, $trendingEmpty] = $this->loadTrending($hardcover, $openLibrary, $libraryIsbns, $libraryKeys);
        $newReleases = $this->shelfFromHardcover($hardcover, 'new_releases', $libraryIsbns, $libraryKeys);
        $upcoming    = $this->shelfFromHardcover($hardcover, 'upcoming', $libraryIsbns, $libraryKeys);
        $staffPicks  = $this->shelfFromHardcover($hardcover, 'staff_picks', $libraryIsbns, $libraryKeys);
        $authors     = $this->popularAuthorsFromHardcover($hardcover);

        $genres = self::BROWSE_GENRES;

        $hardcoverEmpty = $hardcover !== null && $hardcover->isEnabled()
            ? 'Waiting for data to populate. Either wait for the next automatic refresh, or request one now from Settings → Metadata.'
            : 'Enable Hardcover in Settings → Metadata to populate this row.';

        return $this->render('home/index.html.twig', [
            'sections' => [
                ['title' => 'Recently Added',  'subtitle' => 'New arrivals in your library',  'items' => $recentlyAdded],
                ['title' => 'Trending',        'subtitle' => $trendingSubtitle,                'items' => $trendingItems,
                 'empty_message' => $trendingEmpty],
                ['title' => 'New Releases',    'subtitle' => 'Out in the last few months',     'items' => $newReleases,
                 'empty_message' => $hardcoverEmpty],
                ['title' => 'Upcoming',        'subtitle' => 'Releases on the horizon',        'items' => $upcoming,
                 'empty_message' => $hardcoverEmpty],
                ['title' => 'Browse by Genre', 'subtitle' => 'Jump into a category',           'items' => $genres,
                 'kind' => 'genre'],
                ['title' => 'Staff Picks',     'subtitle' => 'Highly rated by readers',        'items' => $staffPicks,
                 'empty_message' => $hardcoverEmpty],
                ['title' => 'Popular Authors', 'subtitle' => 'Most-followed on Hardcover',     'items' => $authors,
                 'kind' => 'author', 'empty_message' => $hardcoverEmpty],
                ['title' => 'Recent Requests', 'subtitle' => 'What your users are asking for', 'items' => [],
                 'kind' => 'request',
                 'empty_message' => 'Book requests will appear here once the request flow is available.'],
            ],
        ]);
    }

    /**
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string}
     */
    private function loadTrending(?Integration $hardcover, ?Integration $openLibrary, array $libraryIsbns, array $libraryKeys): array
    {
        $hardcoverOn = $hardcover !== null && $hardcover->isEnabled();
        $openLibraryOn = $openLibrary !== null && $openLibrary->isEnabled();
        if ($hardcoverOn) {
            $items = $this->cachedBooksAsCards($hardcover, 'trending', $libraryIsbns, $libraryKeys);
            if ($items !== []) {
                return [$items, 'Trending on Hardcover', ''];
            }
        }
        if ($openLibraryOn) {
            $items = $this->cachedBooksAsCards($openLibrary, 'trending', $libraryIsbns, $libraryKeys);
            if ($items !== []) {
                return [$items, 'Trending on Open Library', ''];
            }
        }
        $empty = $hardcoverOn || $openLibraryOn
            ? 'Waiting for data to populate. Either wait for the next automatic refresh, or request one now from Settings → Metadata.'
            : 'Enable Hardcover or Open Library in Settings → Metadata to populate this row.';
        return [[], $hardcoverOn ? 'Trending on Hardcover' : ($openLibraryOn ? 'Trending on Open Library' : ''), $empty];
    }

    /**
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @return list<array<string, mixed>>
     */
    private function shelfFromHardcover(?Integration $hardcover, string $slot, array $libraryIsbns, array $libraryKeys): array
    {
        if ($hardcover === null || !$hardcover->isEnabled()) {
            return [];
        }
        return $this->cachedBooksAsCards($hardcover, $slot, $libraryIsbns, $libraryKeys);
    }

    /**
     * @param array<string, true> $libraryIsbns
     * @param array<string, true> $libraryKeys
     * @return list<array<string, mixed>>
     */
    private function cachedBooksAsCards(Integration $integration, string $slot, array $libraryIsbns, array $libraryKeys): array
    {
        $books = $integration->getCacheData()[$slot]['books'] ?? [];
        if (!is_array($books)) {
            return [];
        }
        $out = [];
        foreach ($books as $b) {
            if (!is_array($b) || empty($b['title'])) {
                continue;
            }
            $title = (string) $b['title'];
            $author = isset($b['author']) ? (string) $b['author'] : null;
            $remoteCover = $b['coverUrl'] ?? null;
            $isbns = is_array($b['isbns'] ?? null) ? $b['isbns'] : [];
            // ISBN match is the canonical "have" check. Title|author is the
            // fallback when neither side has an ISBN, for older cached payloads
            // and books not yet re-synced from Grimmory.
            $downloaded = false;
            foreach ($isbns as $isbn) {
                if (!is_string($isbn) && !is_int($isbn)) {
                    continue;
                }
                $key = (string) $isbn;
                if (isset($libraryIsbns[$key])) {
                    $downloaded = true;
                    break;
                }
            }
            if (!$downloaded && $isbns === []) {
                $key = BookRepository::normalizeTitleAuthor($title, $author);
                $downloaded = $key !== null && isset($libraryKeys[$key]);
            }
            $externalUrl = is_string($b['externalUrl'] ?? null) ? $b['externalUrl'] : null;
            [$metaSource, $metaExternalId] = $this->trendingMetadataKey($integration->getKind(), $externalUrl);
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
     * @return list<array{name: string, slug: ?string, image_url: ?string, image_remote_url: ?string, external_url: ?string}>
     */
    private function popularAuthorsFromHardcover(?Integration $hardcover): array
    {
        if ($hardcover === null || !$hardcover->isEnabled()) {
            return [];
        }
        $rows = $hardcover->getCacheData()['popular_authors']['authors'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $image = $row['imageUrl'] ?? null;
            $slug = $row['slug'] ?? null;
            $remote = is_string($image) && $image !== '' ? $image : null;
            $out[] = [
                'name' => (string) $row['name'],
                'slug' => is_string($slug) && $slug !== '' ? $slug : null,
                'image_url' => $remote !== null ? $this->covers->proxyUrlForRemote($remote) : null,
                // Upstream URL passed through so the popup can seed a fresh author row.
                'image_remote_url' => $remote,
                'external_url' => is_string($row['externalUrl'] ?? null) ? $row['externalUrl'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return array{title: string, author: ?string, downloaded: bool, cover_url: string, meta_id: ?int}
     */
    private function bookToCard(Book $book): array
    {
        $title = $book->getTitle();
        if ($book->getSeries() !== null && $book->getSeriesIndex() !== null) {
            $title = sprintf('%s (%s #%s)', $title, $book->getSeries(), $book->getSeriesIndex());
        }
        return [
            'title' => $title,
            'author' => $book->getAuthor(),
            'downloaded' => $book->isDownloaded(),
            'cover_url' => $this->covers->proxyUrlForKomga($book->getExternalId()),
            'meta_id' => $book->getId(),
        ];
    }

    /**
     * Returns nulls when the URL doesn't match the integration's slug scheme (card stays non-clickable).
     *
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

    #[Route('/healthz', name: 'healthz')]
    public function healthz(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
