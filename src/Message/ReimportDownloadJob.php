<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Re-import ONE completed torrent download into the library, re-driving the full
 * finalize pipeline (whole-folder move, missing-tag fill, Grimmory sidecar,
 * delayed import trigger) from the torrent's raw files — available only while
 * those files still exist (torrent still seeding, or remove-on-complete off).
 * Carries only the job id; the handler reloads the job and re-checks every
 * precondition, so a stale or redelivered message can never throw.
 */
final readonly class ReimportDownloadJob
{
    public function __construct(
        public int $downloadJobId,
    ) {
    }
}
