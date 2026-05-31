<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\FulfillmentLog;
use App\Message\PruneFulfillmentEvents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneFulfillmentEventsHandler
{
    /** Activity entries older than this are pruned (2 hours). */
    private const MAX_AGE_SECONDS = 7200;

    public function __construct(
        private readonly FulfillmentLog $log,
    ) {
    }

    public function __invoke(PruneFulfillmentEvents $message): void
    {
        $this->log->pruneOlderThan(self::MAX_AGE_SECONDS);
    }
}
