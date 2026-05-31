<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DispatchReleaseSearch;
use App\Message\RetryApprovedSearches;
use App\Repository\BookRequestRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-fires a release search for every approved request still missing a download.
 * DispatchReleaseSearchHandler is idempotent (it skips requests that already have
 * an in-flight job), so this safely re-scans without duplicating work.
 */
#[AsMessageHandler]
final class RetryApprovedSearchesHandler
{
    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RetryApprovedSearches $message): void
    {
        foreach ($this->requests->findApprovedNeedingSearch() as $request) {
            $this->bus->dispatch(new DispatchReleaseSearch((int) $request->getId()));
        }
    }
}
