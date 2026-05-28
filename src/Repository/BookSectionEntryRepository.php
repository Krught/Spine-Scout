<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookSectionEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookSectionEntry>
 */
final class BookSectionEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookSectionEntry::class);
    }

    /**
     * Wipe-and-rewrite every entry for a `(source, section)` pair. The refresh handlers
     * always send the full shelf so we don't need partial-update semantics.
     *
     * @param list<int> $bookIdsInRankOrder zero-based; rank 1 = first id.
     */
    public function replaceSection(string $source, string $section, array $bookIdsInRankOrder, \DateTimeImmutable $fetchedAt): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'DELETE FROM book_section_entries WHERE source = :source AND section = :section',
                ['source' => $source, 'section' => $section],
            );

            if ($bookIdsInRankOrder !== []) {
                $now = new \DateTimeImmutable();
                $sql = 'INSERT INTO book_section_entries (source, section, book_id, rank, fetched_at, created_at) VALUES (:source, :section, :book_id, :rank, :fetched_at, :created_at)';
                $stmt = $conn->prepare($sql);
                $rank = 1;
                foreach ($bookIdsInRankOrder as $bookId) {
                    $stmt->bindValue('source', $source);
                    $stmt->bindValue('section', $section);
                    $stmt->bindValue('book_id', $bookId, ParameterType::INTEGER);
                    $stmt->bindValue('rank', $rank, ParameterType::INTEGER);
                    $stmt->bindValue('fetched_at', $fetchedAt->format('Y-m-d H:i:s'));
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
     * MAX(fetched_at) for a `(source, section)` pair. Used to derive the "last refreshed"
     * timestamp for each shelf now that integrations.cache_data is gone.
     */
    public function lastFetchedAt(string $source, string $section): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('e')
            ->select('MAX(e.fetchedAt)')
            ->where('e.source = :source')
            ->andWhere('e.section = :section')
            ->setParameter('source', $source)
            ->setParameter('section', $section)
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
