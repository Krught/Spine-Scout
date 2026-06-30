<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\FulfillmentLog;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Message\DispatchTorrentSearch;
use App\Message\ProcessTorrentJob;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * On approval of an audiobook request: queue a torrent DownloadJob and hand off to
 * ProcessTorrentJob, which searches Prowlarr and submits the best match to
 * qBittorrent. Idempotent — skips requests that already have an in-flight job — so
 * RetryApprovedSearches can re-fire safely.
 */
#[AsMessageHandler]
final class DispatchTorrentSearchHandler
{
    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly DownloadJobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FulfillmentLog $log,
    ) {
    }

    public function __invoke(DispatchTorrentSearch $message): void
    {
        $request = $this->requests->find($message->bookRequestId);
        if ($request === null || $request->getStatus() !== BookRequest::STATUS_APPROVED) {
            return;
        }
        if ($this->jobs->hasActiveJobForRequest($request)) {
            return;
        }

        $this->log->info('Searching indexers for an audiobook…', $request->getBook()->getTitle());

        $job = new DownloadJob(
            source: 'pending',
            sourceId: '',
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            bookRequest: $request,
        );
        $job->setStatus(DownloadJob::STATUS_QUEUED);
        $request->setDeliveryStatus(DownloadJob::STATUS_QUEUED);

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new ProcessTorrentJob((int) $job->getId()));
    }
}
