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
}
