<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off the fulfillment loop for a just-approved request: search enabled
 * release sources, score candidates, auto-pick the best match, and create a
 * DownloadJob. Carries only the request id — the request is reloaded fresh in
 * the handler so a stale snapshot never drives a download.
 */
final readonly class DispatchReleaseSearch
{
    public function __construct(
        public int $bookRequestId,
    ) {
    }
}
