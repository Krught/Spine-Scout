<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DispatchReleaseSearch;
use App\Message\RetryApprovedSearches;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-fires a release search for every approved request still missing a download.
 * First reclaims orphaned in-flight jobs (a worker that died/restarted mid-download
 * leaves a job stuck "downloading" forever, blocking both this retry and
 * hasActiveJobForRequest()) — reclaiming marks them errored so
 * findApprovedNeedingSearch() picks them up in the same pass.
 * DispatchReleaseSearchHandler is idempotent (skips requests with a live job), so
 * this safely re-scans without duplicating work.
 */
#[AsMessageHandler]
final class RetryApprovedSearchesHandler
{
    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly DownloadJobRepository $jobs,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RetryApprovedSearches $message): void
    {
        $this->jobs->reclaimStale();

        foreach ($this->requests->findApprovedNeedingSearch() as $request) {
            $this->bus->dispatch(new DispatchReleaseSearch((int) $request->getId()));
        }
    }
}
