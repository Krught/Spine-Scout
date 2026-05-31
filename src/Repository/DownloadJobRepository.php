<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadJob>
 */
final class DownloadJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadJob::class);
    }

    public function findLatestForRequest(BookRequest $request): ?DownloadJob
    {
        return $this->findOneBy(['bookRequest' => $request], ['createdAt' => 'DESC']);
    }

    /**
     * Most-recently-updated jobs (with request + book eager-loaded) for the dev
     * activity view.
     *
     * @return list<DownloadJob>
     */
    public function recent(int $limit = 40): array
    {
        /** @var list<DownloadJob> $rows */
        $rows = $this->createQueryBuilder('j')
            ->leftJoin('j.bookRequest', 'r')->addSelect('r')
            ->leftJoin('r.book', 'b')->addSelect('b')
            ->orderBy('j.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * True when the request already has a download job that hasn't reached a
     * terminal state — used to avoid spawning a duplicate search/download when
     * approve is double-clicked or the message is redelivered.
     */
    public function hasActiveJobForRequest(BookRequest $request): bool
    {
        $count = (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.bookRequest = :request')
            ->andWhere('j.status NOT IN (:terminal)')
            ->setParameter('request', $request)
            ->setParameter('terminal', DownloadJob::TERMINAL_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Latest job per request id, for rendering delivery status on the list.
     *
     * @param list<int> $requestIds
     * @return array<int, DownloadJob> requestId => latest job
     */
    public function latestByRequestIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        /** @var list<DownloadJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->where('j.bookRequest IN (:ids)')
            ->setParameter('ids', $requestIds)
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($jobs as $job) {
            $rid = $job->getBookRequest()?->getId();
            if ($rid !== null && !isset($latest[$rid])) {
                $latest[$rid] = $job;
            }
        }

        return $latest;
    }
}
