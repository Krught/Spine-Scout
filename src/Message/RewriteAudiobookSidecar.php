<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Re-write the Grimmory metadata/cover sidecar for ONE completed audiobook download
 * job. Carries only the job id; the handler reloads the job, recovers the album
 * folder from its stored file path, and re-emits "<folder>.metadata.json" and
 * "<folder>.cover.jpg" beside it. Safe to redeliver — the write is idempotent.
 */
final readonly class RewriteAudiobookSidecar
{
    public function __construct(
        public int $downloadJobId,
    ) {
    }
}
