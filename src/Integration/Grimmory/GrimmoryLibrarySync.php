<?php

declare(strict_types=1);

namespace App\Integration\Grimmory;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Grimmory\Dto\BookSummary;
use App\Integration\Grimmory\Dto\LibraryEntry;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Diffs Grimmory (Komga) against the local `books` table:
 *   new ids INSERT (addedAt seeded from Komga's `created`, or `now` fallback);
 *   existing ids whose `lastModified` advanced UPDATE;
 *   ids no longer returned soft-delete (set removed_at), but only swept
 *   within libraries actually scanned this run so partial library selections
 *   don't false-positive everything else;
 *   ids that reappear after removal un-remove.
 */
final class GrimmoryLibrarySync
{
    public function __construct(
        private readonly GrimmoryClient $client,
        private readonly BookRepository $books,
        private readonly IntegrationRepository $integrations,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function syncIfConfigured(): SyncResult
    {
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            $this->logger->info('Grimmory sync skipped: integration not enabled.');
            return SyncResult::skipped();
        }
        return $this->sync($integration);
    }

    public function sync(Integration $integration): SyncResult
    {
        $now = new \DateTimeImmutable();
        $existing = $this->books->findAllBySourceKeyedByExternalId(Book::SOURCE_GRIMMORY);
        $seen = [];
        $newExternalIds = [];
        $added = $updated = 0;

        try {
            $libraries = $this->client->discoverLibraries($integration);
            $integration->setDiscoveredLibraries(array_map(static fn (LibraryEntry $l) => $l->toArray(), $libraries));

            $filter = $this->resolveLibraryFilter($integration, $libraries);

            foreach ($this->client->listBooks($integration, $filter) as $summary) {
                $seen[$summary->externalId] = true;
                $book = $existing[$summary->externalId] ?? null;

                if ($book === null) {
                    $book = new Book(Book::SOURCE_GRIMMORY, $summary->externalId, $summary->title);
                    $this->applyFromSummary($book, $summary, $now);
                    // First import: seed addedAt from Komga or fall back to now.
                    $book->setAddedAt($summary->addedAt ?? $now);
                    $this->em->persist($book);
                    $added++;
                    $newExternalIds[] = $summary->externalId;
                } else {
                    $changed = $this->hasMaterialChange($book, $summary);
                    if ($changed || $book->isRemoved()) {
                        $this->applyFromSummary($book, $summary, $now);
                        if ($summary->addedAt !== null && $book->getAddedAt() === null) {
                            // Backfill addedAt if a previous sync missed it.
                            $book->setAddedAt($summary->addedAt);
                        }
                        $book->setRemovedAt(null);
                        $updated++;
                    } else {
                        $book->setLastSeenAt($now);
                        // Re-check ISBN every sync: Komga's lastModified doesn't always
                        // tick when only the ISBN field is edited.
                        if ($book->getIsbn() !== $summary->isbn) {
                            $book->setIsbn($summary->isbn);
                            $updated++;
                        }
                    }
                }
            }
        } catch (GrimmoryException $e) {
            $integration->setLastError($e->getMessage());
            $this->em->flush();
            throw $e;
        }

        $removed = $this->sweepMissing($existing, $seen, $integration, $now);

        $integration->setLastSyncAt($now);
        $integration->setLastError(null);
        $this->em->flush();

        $this->logger->info('Grimmory sync complete', [
            'added' => $added, 'updated' => $updated, 'removed' => $removed, 'seen' => count($seen),
        ]);

        return new SyncResult(true, $added, $updated, $removed, count($seen), $newExternalIds);
    }

    /**
     * Returns null for "all libraries" (nothing selected, or every advertised library selected).
     *
     * @param list<LibraryEntry> $advertised
     * @return list<string>|null
     */
    private function resolveLibraryFilter(Integration $integration, array $advertised): ?array
    {
        $selected = $integration->getSelectedLibraries();
        if ($selected === []) {
            return null;
        }
        $advertisedIds = array_map(static fn (LibraryEntry $l) => $l->id, $advertised);
        $intersection = array_values(array_intersect($selected, $advertisedIds));
        if ($intersection === [] || count($intersection) === count($advertisedIds)) {
            return null;
        }
        return $intersection;
    }

    private function hasMaterialChange(Book $book, BookSummary $s): bool
    {
        // Trust Komga's lastModified when present; it ticks on metadata/file edits.
        if ($s->lastModifiedAt !== null) {
            $stored = $book->getLastModifiedAt();
            return $stored === null || $s->lastModifiedAt > $stored;
        }
        // Fallback for older Komga responses that omit lastModified.
        return $book->getTitle() !== $s->title
            || $book->getAuthor() !== $s->author
            || $book->getSeries() !== $s->series
            || $book->getSeriesIndex() !== $s->seriesIndex
            || $book->getExternalUrl() !== $s->externalUrl;
    }

    private function applyFromSummary(Book $book, BookSummary $s, \DateTimeImmutable $now): void
    {
        $book->setTitle($s->title);
        $book->setAuthor($s->author);
        $book->setSeries($s->series);
        $book->setSeriesIndex($s->seriesIndex);
        $book->setExternalUrl($s->externalUrl);
        $book->setKomgaLibraryId($s->libraryId);
        $book->setIsbn($s->isbn);
        $book->setLastModifiedAt($s->lastModifiedAt);
        $book->setLastSeenAt($now);
    }

    /**
     * Soft-delete rows we didn't see, but only inside libraries we actually
     * scanned this run. A null filter means "everything"; otherwise restrict
     * the sweep to rows whose komga_library_id is in the filter.
     *
     * @param array<string, Book> $existing
     * @param array<string, bool> $seen
     */
    private function sweepMissing(array $existing, array $seen, Integration $integration, \DateTimeImmutable $now): int
    {
        $filter = $integration->getSelectedLibraries();
        $advertisedIds = array_map(static fn (array $l) => $l['id'], $integration->getDiscoveredLibraries());
        $effectiveFilter = ($filter === [] || count(array_intersect($filter, $advertisedIds)) === count($advertisedIds))
            ? null
            : array_flip($filter);

        $removed = 0;
        foreach ($existing as $externalId => $book) {
            if (isset($seen[$externalId]) || $book->isRemoved()) {
                continue;
            }
            if ($effectiveFilter !== null) {
                $lib = $book->getKomgaLibraryId();
                if ($lib === null || !isset($effectiveFilter[$lib])) {
                    continue; // outside the libraries we scanned, can't reason about
                }
            }
            $book->setRemovedAt($now);
            $removed++;
        }
        return $removed;
    }
}
