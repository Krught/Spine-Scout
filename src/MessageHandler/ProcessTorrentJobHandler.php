<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Entity\Book;
use App\Entity\DownloadJob;
use App\Message\ProcessTorrentJob;
use App\Repository\BookRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Download\FulfillmentLog;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves one audiobook DownloadJob to a torrent and submits it to the download
 * client (via the shared TorrentFulfillment step). Returns immediately — the
 * download runs asynchronously and PollTorrentJobs finalizes it. On any failure
 * the job is errored and the request stays APPROVED so RetryApprovedSearches
 * re-runs the search later.
 */
#[AsMessageHandler]
final class ProcessTorrentJobHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TorrentFulfillmentInterface $torrents,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessTorrentJob $message): void
    {
        $job = $this->claim($message->downloadJobId);
        if ($job === null) {
            return;
        }

        $request = $job->getBookRequest();
        if ($request === null) {
            $this->fail($job, 'Torrent job has no associated request.');

            return;
        }

        $subject = $request->getBook()->getTitle();
        if (!$this->torrents->isAvailable()) {
            $this->fail($job, 'No indexers / download client configured — cannot download audiobooks.');

            return;
        }

        $plan = $this->planFor($request->getBook());

        try {
            $added = $this->torrents->tryFulfill($job, $plan, $subject);
        } catch (\Throwable $e) {
            $this->fail($job, 'Download client add failed: ' . $e->getMessage());

            return;
        }

        if (!$added) {
            $this->fail($job, 'No audiobook torrents cleared the seed/size/match criteria.');

            return;
        }

        $this->em->flush();
        $this->logger->info('Torrent queued', ['job' => $job->getId(), 'hash' => $job->getClientRef()]);
    }

    /**
     * Claim under a row lock. Proceeds when QUEUED, or when a prior attempt died
     * mid-resolve (RESOLVING but no client_ref) so a retry can resume rather than
     * strand the job. Re-adding is safe — the download client dedupes by infohash.
     */
    private function claim(int $jobId): ?DownloadJob
    {
        return $this->em->wrapInTransaction(function () use ($jobId): ?DownloadJob {
            $job = $this->em->find(DownloadJob::class, $jobId, LockMode::PESSIMISTIC_WRITE);
            if ($job === null) {
                return null;
            }
            $resumable = $job->getStatus() === DownloadJob::STATUS_QUEUED
                || ($job->getStatus() === DownloadJob::STATUS_RESOLVING && ($job->getClientRef() ?? '') === '');
            if (!$resumable) {
                return null;
            }
            $job->setStatus(DownloadJob::STATUS_RESOLVING)->setProgress(0);
            $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_RESOLVING);

            return $job;
        });
    }

    private function planFor(Book $book): ReleaseSearchPlan
    {
        $isbns = [];
        $seen = [];
        foreach ([$book->getIsbn(), ...$book->getIsbns()] as $raw) {
            $normalized = BookRepository::normalizeIsbn($raw);
            if ($normalized !== null && !isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $isbns[] = $normalized;
            }
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: $isbns,
            author: (string) $book->getAuthor(),
            titleVariants: [$book->getTitle()],
            contentType: ReleaseCandidate::CONTENT_AUDIOBOOK,
        );
    }

    private function fail(DownloadJob $job, string $message): void
    {
        $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($message);
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_ERROR);
        $this->em->flush();
        $this->log->error('Audiobook search failed: ' . $message, $job->getBookRequest()?->getBook()->getTitle());
        $this->logger->warning('Torrent job failed', ['job' => $job->getId(), 'error' => $message]);
    }
}
