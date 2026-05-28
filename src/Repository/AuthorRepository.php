<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Author;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Author::class);
    }

    public function findOneBySourceAndSlug(string $source, string $slug): ?Author
    {
        return $this->findOneBy(['source' => $source, 'slug' => $slug]);
    }

    /**
     * Authors with a current `popular_rank`, ordered by it.
     *
     * @return list<Author>
     */
    public function findPopular(string $source, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.source = :s')
            ->andWhere('a.popularRank IS NOT NULL')
            ->setParameter('s', $source)
            ->orderBy('a.popularRank', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Most-recent `popular_fetched_at` across all popular authors for a source — used as the
     * "last refreshed" timestamp for the popular-authors shelf now that integrations.cache_data
     * is gone.
     */
    public function popularLastFetchedAt(string $source): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('a')
            ->select('MAX(a.popularFetchedAt)')
            ->where('a.source = :s')
            ->andWhere('a.popularRank IS NOT NULL')
            ->setParameter('s', $source)
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
