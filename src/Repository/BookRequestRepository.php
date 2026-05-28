<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use App\Entity\BookRequest;
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
     * Status maps for the current user's requests, keyed two ways so cards can match
     * either by ISBN (preferred) or by normalized title|author (fallback for cached
     * remote rows without ISBNs).
     *
     * @return array{isbns: array<string, string>, titleAuthor: array<string, string>}
     */
    public function statusMapsForUser(User $user): array
    {
        /** @var list<array{status: string, isbn: ?string, title: ?string, author: ?string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'b.isbn AS isbn', 'b.title AS title', 'b.author AS author')
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
