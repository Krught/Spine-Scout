<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Integration\MyAnonamouse\MamFreeleechRefresher;
use App\Message\ResolveMamFreeleech;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drains the pending backlog a batch at a time. The five-minute schedule only kick-starts
 * the drain: a run that leaves work behind, made forward progress and hit no error chains
 * straight into the next batch instead of idling until the next tick, so a large backlog
 * is worked off as fast as Hardcover allows rather than at one batch per five minutes.
 */
#[AsMessageHandler]
final class ResolveMamFreeleechHandler
{
    public function __construct(
        private readonly MamFreeleechRefresher $refresher,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolveMamFreeleech $message): void
    {
        $summary = $this->refresher->resolvePending($message->maxResolutions ?? MamFreeleechRefresher::RESOLVE_CRON_BATCH);
        if ($summary['skipped']) {
            return;
        }

        $this->logger->info('MyAnonamouse freeleech resolution ran', $summary);

        // Chain the next batch only on a run that both left work behind and moved: an error
        // (a Hardcover 429 above all) must fall through to the backoff and the schedule, and
        // a run that resolved nothing would chain forever, so both stop the chain here.
        if ($summary['pendingLeft'] > 0
            && $summary['error'] === null
            && ($summary['resolved'] + $summary['unmatched']) > 0
        ) {
            $this->bus->dispatch(new ResolveMamFreeleech($message->maxResolutions));
        }
    }
}
