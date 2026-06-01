<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Download\FulfillmentLog;
use App\Message\DispatchReleaseSearch;
use App\Message\ProcessDownloadJob;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * On approval: queue a download job for the request and hand off to
 * ProcessDownloadJob, which runs the full direct-download cascade (search every
 * enabled source, score, and try the top-3 items across each source's mirrors in
 * priority order). Idempotent — skips requests that already have an in-flight job
 * — so the 3-hourly RetryApprovedSearches can re-fire safely.
 *
 * The actual "what can we download?" decision lives entirely in the cascade
 * (ProcessDownloadJobHandler); a request with no qualifying match anywhere ends
 * as an errored job and stays APPROVED for the next retry.
 */
#[AsMessageHandler]
final class DispatchReleaseSearchHandler
{
    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly DownloadJobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FulfillmentLog $log,
    ) {
    }

    public function __invoke(DispatchReleaseSearch $message): void
    {
        $request = $this->requests->find($message->bookRequestId);
        if ($request === null || $request->getStatus() !== BookRequest::STATUS_APPROVED) {
            return;
        }
        // Idempotency: don't spawn a second job if one is already in flight.
        if ($this->jobs->hasActiveJobForRequest($request)) {
            return;
        }

        $this->log->info('Searching for a release…', $request->getBook()->getTitle());

        // The winning source/item is unknown until the cascade runs, so the job
        // starts with placeholders; ProcessDownloadJobHandler stamps it on success.
        $job = new DownloadJob(
            source: 'pending',
            sourceId: '',
            protocol: ReleaseCandidate::PROTOCOL_HTTP,
            bookRequest: $request,
        );
        $job->setStatus(DownloadJob::STATUS_QUEUED);
        $request->setDeliveryStatus(DownloadJob::STATUS_QUEUED);

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new ProcessDownloadJob((int) $job->getId()));
    }
}
