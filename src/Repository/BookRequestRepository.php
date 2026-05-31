<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookRequest>
 */
final class BookRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookRequest::class);
    }

    /**
     * @return list<BookRequest>
     */
    public function findAllForList(): array
    {
        /** @var list<BookRequest> $rows */
        $rows = $this->createQueryBuilder('r')
            ->addSelect('u', 'b')
            ->leftJoin('r.requestedBy', 'u')
            ->leftJoin('r.book', 'b')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneByUserAndBook(User $user, Book $book): ?BookRequest
    {
        return $this->findOneBy(['requestedBy' => $user, 'book' => $book]);
    }

    /**
     * Approved requests that still need a release search: no download job that is
     * in-progress (queued/resolving/downloading) or already complete. Requests
     * whose only jobs errored/cancelled are included — those are the retries.
     *
     * @return list<BookRequest>
     */
    public function findApprovedNeedingSearch(): array
    {
        /** @var list<BookRequest> $rows */
        $rows = $this->createQueryBuilder('r')
            ->addSelect('b')
            ->leftJoin('r.book', 'b')
            ->where('r.status = :approved')
            ->andWhere($this->getEntityManager()->createQueryBuilder()->expr()->notIn(
                'r.id',
                'SELECT IDENTITY(j.bookRequest) FROM App\Entity\DownloadJob j WHERE j.status IN (:liveStatuses)',
            ))
            ->setParameter('approved', BookRequest::STATUS_APPROVED)
            ->setParameter('liveStatuses', [
                DownloadJob::STATUS_QUEUED,
                DownloadJob::STATUS_RESOLVING,
                DownloadJob::STATUS_DOWNLOADING,
                DownloadJob::STATUS_COMPLETE,
            ])
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Flip APPROVED requests to AVAILABLE once their book is present in the
     * downloaded library — matched by ISBN (any edition) first, then by
     * normalized title|author. Called after a library sync so a freshly imported
     * file closes out the request that asked for it.
     *
     * @param array<string, true> $downloadedIsbns       map of normalized ISBN => true
     * @param array<string, true> $downloadedTitleAuthor map of "title|author" => true
     * @return int number of requests flipped
     */
    public function markAvailableForDownloaded(array $downloadedIsbns, array $downloadedTitleAuthor): int
    {
        if ($downloadedIsbns === [] && $downloadedTitleAuthor === []) {
            return 0;
        }

        /** @var list<BookRequest> $approved */
        $approved = $this->createQueryBuilder('r')
            ->addSelect('b')
            ->leftJoin('r.book', 'b')
            ->where('r.status = :approved')
            ->setParameter('approved', BookRequest::STATUS_APPROVED)
            ->getQuery()
            ->getResult();

        $flipped = 0;
        foreach ($approved as $request) {
            if ($this->isDownloaded($request->getBook(), $downloadedIsbns, $downloadedTitleAuthor)) {
                $request->setStatus(BookRequest::STATUS_AVAILABLE);
                ++$flipped;
            }
        }

        if ($flipped > 0) {
            $this->getEntityManager()->flush();
        }

        return $flipped;
    }

    /**
     * @param array<string, true> $isbns
     * @param array<string, true> $titleAuthor
     */
    private function isDownloaded(Book $book, array $isbns, array $titleAuthor): bool
    {
        foreach ([$book->getIsbn(), ...$book->getIsbns()] as $raw) {
            $normalized = BookRepository::normalizeIsbn($raw);
            if ($normalized !== null && isset($isbns[$normalized])) {
                return true;
            }
        }

        $key = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());

        return $key !== null && isset($titleAuthor[$key]);
    }

    /**
     * Status maps for the current user's requests, keyed two ways so cards can match
     * either by ISBN (preferred) or by normalized title|author (fallback for cached
     * remote rows without ISBNs).
     *
     * @return array{isbns: array<string, string>, titleAuthor: array<string, string>}
     */
    public function statusMapsForUser(User $user): array
    {
        /** @var list<array{status: string, deliveryStatus: ?string, isbn: ?string, title: ?string, author: ?string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'r.deliveryStatus AS deliveryStatus', 'b.isbn AS isbn', 'b.title AS title', 'b.author AS author')
            ->leftJoin('r.book', 'b')
            ->where('r.requestedBy = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getArrayResult();

        $isbns = [];
        $titleAuthor = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === '') {
                continue;
            }
            // Surface "downloaded but not yet imported" as a distinct pseudo-status so
            // cards can show a download icon (vs. the plain approved/thumbs-up icon).
            if ($status === BookRequest::STATUS_APPROVED && ($row['deliveryStatus'] ?? null) === DownloadJob::STATUS_COMPLETE) {
                $status = 'downloaded';
            }
            $isbn = $row['isbn'] ?? null;
            if (is_string($isbn) && $isbn !== '') {
                $isbns[$isbn] = $status;
            }
            $key = BookRepository::normalizeTitleAuthor((string) ($row['title'] ?? ''), $row['author'] ?? null);
            if ($key !== null) {
                $titleAuthor[$key] = $status;
            }
        }

        return ['isbns' => $isbns, 'titleAuthor' => $titleAuthor];
    }
}
