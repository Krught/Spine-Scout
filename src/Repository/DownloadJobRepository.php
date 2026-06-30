<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Search\Source\ReleaseCandidate;
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

        // Torrent jobs are excluded: they legitimately stay in-flight for a long time
        // while downloading, and the torrent poller owns their lifecycle (progress,
        // completion, and manual-removal detection). Only the synchronous HTTP path
        // can truly orphan a job here.
        /** @var list<DownloadJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->where('j.status IN (:active)')
            ->andWhere('j.updatedAt < :cutoff')
            ->andWhere('j.protocol != :torrent')
            ->setParameter('active', DownloadJob::ACTIVE_STATUSES)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('torrent', ReleaseCandidate::PROTOCOL_TORRENT)
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
     * In-flight torrent jobs (resolving/downloading), request + book eager-loaded.
     * The torrent poller walks these each tick to advance progress and finalize
     * finished torrents. HTTP jobs are excluded by the protocol filter.
     *
     * @return list<DownloadJob>
     */
    public function activeTorrentJobs(): array
    {
        /** @var list<DownloadJob> $rows */
        $rows = $this->createQueryBuilder('j')
            ->leftJoin('j.bookRequest', 'r')->addSelect('r')
            ->leftJoin('r.book', 'b')->addSelect('b')
            ->where('j.protocol = :torrent')
            ->andWhere('j.status IN (:active)')
            ->setParameter('torrent', ReleaseCandidate::PROTOCOL_TORRENT)
            ->setParameter('active', DownloadJob::ACTIVE_STATUSES)
            ->orderBy('j.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
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
     * Ids of every completed audiobook download — the jobs whose stored filePath is
     * the on-disk album folder, so a metadata/cover sidecar can be (re)written beside
     * it. Used to fan out the library-wide "rewrite all sidecars" action.
     *
     * @return list<int>
     */
    public function completedAudiobookJobIds(): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('j.id')
            ->join('j.bookRequest', 'r')
            ->where('j.status = :complete')
            ->andWhere('r.audiobook = true')
            ->andWhere('j.filePath IS NOT NULL')
            ->setParameter('complete', DownloadJob::STATUS_COMPLETE)
            ->orderBy('j.id', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
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
