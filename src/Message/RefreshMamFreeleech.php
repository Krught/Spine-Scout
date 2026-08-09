<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Triggers a refresh of the MyAnonamouse freeleech catalog. `$force = true`
 * bypasses the per-integration refresh interval (the probe command and the
 * manual button).
 */
final readonly class RefreshMamFreeleech
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
