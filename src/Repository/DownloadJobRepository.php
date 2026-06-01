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

    /** A job idle in an in-flight state longer than this is treated as orphaned. */
    public const STALE_AFTER_SECONDS = 1800;

    public function findLatestForRequest(BookRequest $request): ?DownloadJob
    {
        return $this->findOneBy(['bookRequest' => $request], ['createdAt' => 'DESC']);
    }

    /**
     * Reclaim orphaned jobs: a worker that dies/restarts mid-download leaves a job
     * stuck in an in-flight state (queued/resolving/downloading) forever — it
     * blocks both the retry scheduler and hasActiveJobForRequest(). Any in-flight
     * job not touched for $olderThanSeconds is marked errored (and its request's
     * delivery status mirrored) so it becomes retryable again.
     *
     * @return list<DownloadJob> the jobs that were reclaimed
     */
    public function reclaimStale(int $olderThanSeconds = self::STALE_AFTER_SECONDS): array
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$olderThanSeconds} seconds");

        /** @var list<DownloadJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->where('j.status IN (:active)')
            ->andWhere('j.updatedAt < :cutoff')
            ->setParameter('active', DownloadJob::ACTIVE_STATUSES)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($jobs as $job) {
            $job->setStatus(DownloadJob::STATUS_ERROR)
                ->setStatusMessage('Stalled — the worker stopped before finishing; reclaimed for retry.');
            $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_ERROR);
        }
        if ($jobs !== []) {
            $this->getEntityManager()->flush();
        }

        return $jobs;
    }

    /**
     * Force-cancel every in-flight job for one request (regardless of age) — used
     * when an admin clicks "Recheck now" so a fresh search/download can start
     * without the old job blocking it.
     *
     * @return int number of jobs cancelled
     */
    public function cancelActiveForRequest(BookRequest $request): int
    {
        /** @var list<DownloadJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->where('j.bookRequest = :request')
            ->andWhere('j.status IN (:active)')
            ->setParameter('request', $request)
            ->setParameter('active', DownloadJob::ACTIVE_STATUSES)
            ->getQuery()
            ->getResult();

        foreach ($jobs as $job) {
            $job->setStatus(DownloadJob::STATUS_CANCELLED)
                ->setStatusMessage('Cancelled by re-check.');
        }
        if ($jobs !== []) {
            $request->setDeliveryStatus(DownloadJob::STATUS_CANCELLED);
            $this->getEntityManager()->flush();
        }

        return \count($jobs);
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
