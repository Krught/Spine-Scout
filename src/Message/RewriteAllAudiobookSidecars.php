<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Re-write the Grimmory metadata/cover sidecar for EVERY completed audiobook
 * download. The handler enumerates the completed audiobook jobs (the only library
 * items with a known, writable on-disk album folder) and fans out one
 * {@see RewriteAudiobookSidecar} per job, so each rewrite is processed and retried
 * independently.
 */
final readonly class RewriteAllAudiobookSidecars
{
}
