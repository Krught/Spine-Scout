<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Drives one DownloadJob to completion: fetch the file (trying each candidate
 * link in order), move it into the configured output folder, and update the
 * job + request delivery status. Carries only the job id; the job is reloaded
 * and claimed under a row lock in the handler so duplicate delivery is safe.
 */
final readonly class ProcessDownloadJob
{
    public function __construct(
        public int $downloadJobId,
    ) {
    }
}
