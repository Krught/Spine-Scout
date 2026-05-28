<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\BookSectionEntry;
use App\Entity\Integration;
use App\Integration\Hardcover\Dto\PopularAuthor;
use App\Integration\Hardcover\Dto\TrendingBook;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Message\RefreshHardcoverTrending;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\BookSectionEntryRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Refreshes every Hardcover-backed homepage shelf in one pass: trending,
 * new releases, upcoming, staff picks, and popular authors.
 *
 * Each shelf used to live in `Integration.cacheData`; now every entry becomes a row in
 * the `books` table (with `downloaded=false` if we don't own it) plus a link row in
 * `book_section_entries`. Popular-author rank lives directly on the `authors` table.
 * One failing shelf still doesn't blank the others — the homepage shows an empty state
 * for the affected row only.
 */
#[AsMessageHandler]
final class RefreshHardcoverTrendingHandler
{
    /**
     * Trending is held to the home page's display size: Hardcover's `books_trending` computes
     * the 30-day ranking on demand and any larger limit (we tried 100 and 200) reliably hits
     * HTTP 408. Deep browse-page scrolls past 25 fall back to the live API pool anyway, so
     * pre-seeding more here would only paper over the upstream limitation.
     */
    private const SHELF_LIMIT = 25;
    private const POPULAR_AUTHORS_LIMIT = 25;

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly HardcoverClient $client,
        private readonly EntityManagerInterface $em,
        private readonly BookRepository $books,
        private readonly BookSectionEntryRepository $sectionEntries,
        private readonly AuthorRepository $authors,
        private readonly CoverCache $covers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshHardcoverTrending $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasCredentials()) {
            return;
        }

        if (!$message->force && !$this->isDue($integration)) {
            return;
        }

        $errors = [];
        $coverUrls = [];
        $syncStartedAt = new \DateTimeImmutable();

        $shelves = [
            BookSectionEntry::SECTION_TRENDING     => fn () => $this->client->fetchTrending($integration, self::SHELF_LIMIT),
            BookSectionEntry::SECTION_NEW_RELEASES => fn () => $this->client->fetchNewReleases($integration, self::SHELF_LIMIT),
            BookSectionEntry::SECTION_UPCOMING     => fn () => $this->client->fetchUpcoming($integration, self::SHELF_LIMIT),
            BookSectionEntry::SECTION_STAFF_PICKS  => fn () => $this->client->fetchStaffPicks($integration, self::SHELF_LIMIT),
        ];

        foreach ($shelves as $section => $fetch) {
            try {
                /** @var list<TrendingBook> $books */
                $books = $fetch();
                $bookIds = $this->upsertBooks(Book::SOURCE_HARDCOVER, $books, $syncStartedAt, $coverUrls);
                $this->em->flush();
                $this->sectionEntries->replaceSection(Book::SOURCE_HARDCOVER, $section, $bookIds, $syncStartedAt);
            } catch (HardcoverException $e) {
                $errors[] = $section . ': ' . $e->getMessage();
                $this->logger->warning('Hardcover shelf refresh failed', ['shelf' => $section, 'error' => $e->getMessage()]);
            }
        }

        try {
            /** @var list<PopularAuthor> $popular */
            $popular = $this->client->fetchPopularAuthors($integration, self::POPULAR_AUTHORS_LIMIT);
            $this->upsertPopularAuthors($popular, $syncStartedAt, $coverUrls);
            // Flush the fresh ranks FIRST so the stale-clear UPDATE can see the new
            // popular_fetched_at values. Otherwise the raw SQL sees only the pre-sync
            // timestamps and briefly nulls every rank — including the ones we just set —
            // leaving the home page's Popular Authors row empty until the final flush below.
            $this->em->flush();
            $this->em->getConnection()->executeStatement(
                'UPDATE authors SET popular_rank = NULL WHERE source = :s AND (popular_fetched_at IS NULL OR popular_fetched_at < :cut)',
                ['s' => Author::SOURCE_HARDCOVER, 'cut' => $syncStartedAt->format('Y-m-d H:i:s')],
            );
        } catch (HardcoverException $e) {
            $errors[] = 'popular_authors: ' . $e->getMessage();
            $this->logger->warning('Hardcover popular authors refresh failed', ['error' => $e->getMessage()]);
        }

        // Prewarm the Genre tag vocabulary so user-facing /browse/search?type=genre never pays
        // the ~1000-row fetch. The client itself owns the cache.app entry + 24h TTL; we just
        // make sure the entry exists and is fresh every sync cycle.
        try {
            $tags = $this->client->fetchGenreTags($integration, forceRefresh: true);
            $integration->setHardcoverGenreCount(count($tags));
        } catch (HardcoverException $e) {
            $errors[] = 'genres: ' . $e->getMessage();
            $this->logger->warning('Hardcover genre vocabulary refresh failed', ['error' => $e->getMessage()]);
        }

        $integration->setLastSyncAt(new \DateTimeImmutable());
        $integration->setLastError($errors === [] ? null : implode('; ', $errors));
        $integration->touch();

        $this->em->flush();

        // Pre-warm covers for everything we just refreshed. Failures here are non-fatal; the
        // proxy will fall back to fetching on demand.
        $urls = array_keys($coverUrls);
        if ($urls !== []) {
            $summary = $this->covers->warmAll($urls);
            $this->logger->info('Hardcover cover prewarm complete', $summary);
        }
    }

    /**
     * @param list<TrendingBook> $books
     * @param array<string, bool> $coverUrls collected for prewarm (mutated)
     * @return list<int> book IDs in upstream rank order; entries without a slug are skipped.
     */
    private function upsertBooks(string $source, array $books, \DateTimeImmutable $now, array &$coverUrls): array
    {
        $ids = [];
        foreach ($books as $b) {
            $slug = $this->slugFromExternalUrl($source, $b->externalUrl);
            if ($slug === null) {
                continue;
            }
            $book = $this->books->upsertMetadataBook(
                source: $source,
                externalId: $slug,
                title: $b->title,
                author: $b->author,
                externalUrl: $b->externalUrl,
                coverUrl: $b->coverUrl,
                rawIsbns: $b->isbns,
                now: $now,
            );
            // We can't get the id until after flush, so flush before reading it back. The caller
            // flushes once per shelf so this is at most one extra flush per ~25-200 books.
            if ($book->getId() === null) {
                $this->em->flush();
            }
            /** @var int $id */
            $id = $book->getId();
            $ids[] = $id;
            if ($b->coverUrl !== null && $b->coverUrl !== '') {
                $coverUrls[$b->coverUrl] = true;
            }
        }
        return $ids;
    }

    /**
     * @param list<PopularAuthor> $popular
     * @param array<string, bool> $coverUrls
     */
    private function upsertPopularAuthors(array $popular, \DateTimeImmutable $now, array &$coverUrls): void
    {
        $rank = 1;
        foreach ($popular as $p) {
            if ($p->slug === null || $p->slug === '') {
                continue;
            }
            $author = $this->authors->findOneBySourceAndSlug(Author::SOURCE_HARDCOVER, $p->slug);
            if ($author === null) {
                $author = new Author(Author::SOURCE_HARDCOVER, $p->slug, $p->name);
                $this->em->persist($author);
            } else {
                $author->setName($p->name);
            }
            if ($p->imageUrl !== null) {
                $author->setImageUrl($p->imageUrl);
                $coverUrls[$p->imageUrl] = true;
            }
            if ($p->externalUrl !== null) {
                $author->setExternalUrl($p->externalUrl);
            }
            $author->setPopularRank($rank);
            $author->setPopularFetchedAt($now);
            $rank++;
        }
    }

    /**
     * Parses the stable slug out of an integration's externalUrl. Returns null when the URL
     * doesn't match a known scheme — those entries can't be deterministically deduped, so we
     * drop them rather than risk creating duplicate rows on every sync.
     */
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

    private function isDue(Integration $integration): bool
    {
        $last = $integration->getLastSyncAt();
        if ($last === null) {
            return true;
        }
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $last->getTimestamp();
        return $elapsed >= $integration->getSyncIntervalMinutes() * 60;
    }
}
