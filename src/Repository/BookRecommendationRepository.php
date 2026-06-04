<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use App\Entity\BookRecommendation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookRecommendation>
 */
final class BookRecommendationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookRecommendation::class);
    }

    /**
     * Wipe-and-rewrite every recommendation for a seed book. The service always recomputes
     * the full set, so partial-update semantics aren't needed (mirrors
     * {@see BookSectionEntryRepository::replaceSection()}).
     *
     * @param list<int> $bookIdsInRankOrder zero-based; rank 1 = first id (strongest match).
     */
    public function replaceForSeed(int $seedBookId, array $bookIdsInRankOrder, \DateTimeImmutable $computedAt): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'DELETE FROM book_recommendations WHERE seed_book_id = :seed',
                ['seed' => $seedBookId],
            );

            if ($bookIdsInRankOrder !== []) {
                $now = new \DateTimeImmutable();
                $sql = 'INSERT INTO book_recommendations (seed_book_id, book_id, rank, computed_at, created_at) VALUES (:seed, :book_id, :rank, :computed_at, :created_at)';
                $stmt = $conn->prepare($sql);
                $rank = 1;
                foreach ($bookIdsInRankOrder as $bookId) {
                    // The seed can never recommend itself; skip defensively so the unique
                    // (seed_book_id, book_id) constraint can't trip on a stray self-reference.
                    if ($bookId === $seedBookId) {
                        continue;
                    }
                    $stmt->bindValue('seed', $seedBookId, ParameterType::INTEGER);
                    $stmt->bindValue('book_id', $bookId, ParameterType::INTEGER);
                    $stmt->bindValue('rank', $rank, ParameterType::INTEGER);
                    $stmt->bindValue('computed_at', $computedAt->format('Y-m-d H:i:s'));
                    $stmt->bindValue('created_at', $now->format('Y-m-d H:i:s'));
                    $stmt->executeStatement();
                    $rank++;
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Recommended books for a seed, ordered by rank (strongest first).
     *
     * @return list<Book>
     */
    public function findForSeed(int $seedBookId, int $limit = 240): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->innerJoin(BookRecommendation::class, 'r', 'WITH', 'r.book = b')
            ->where('r.seedBook = :seed')
            ->setParameter('seed', $seedBookId)
            ->orderBy('r.rank', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * MAX(computed_at) for a seed — the freshness probe used to decide whether to recompute.
     */
    public function computedAtForSeed(int $seedBookId): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('r')
            ->select('MAX(r.computedAt)')
            ->where('r.seedBook = :seed')
            ->setParameter('seed', $seedBookId)
            ->getQuery()
            ->getSingleScalarResult();

        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
