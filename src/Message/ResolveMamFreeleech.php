<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Drains the MyAnonamouse freeleech pending → resolved backlog. Rides its own
 * five-minute schedule so a large backlog is worked off in small batches instead of
 * waiting on the six-hourly fetch sweep. `$maxResolutions` caps the Hardcover lookups
 * one run makes; null keeps the refresher's full pending batch.
 */
final readonly class ResolveMamFreeleech
{
    public function __construct(
        public ?int $maxResolutions = null,
    ) {
    }
}
