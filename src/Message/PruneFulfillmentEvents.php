<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Triggers pruning of old rows from the fulfillment activity log so the dev
 * Download Activity monitor only ever shows recent entries. Dispatched by the
 * scheduler.
 */
final readonly class PruneFulfillmentEvents
{
}
