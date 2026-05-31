<?php

declare(strict_types=1);

namespace App\Download\Progress;

/**
 * A sink for fine-grained, user-facing download progress — the stages within a
 * single candidate-link attempt (opening the bypass, clearing the challenge,
 * finding the partner link, streaming the file). The download client and the
 * bypassers call this so the Download Activity monitor can show *where* an
 * attempt got to, rather than just a final "Link N/M failed".
 *
 * Decoupled on purpose: the client/bypassers depend on this thin interface, not
 * on FulfillmentLog. NullDownloadProgressReporter is the no-op default when no
 * reporter is supplied (e.g. direct unit-test calls).
 */
interface DownloadProgressReporter
{
    /** A normal forward step (info level). */
    public function step(string $message): void;

    /** A non-fatal problem at this stage — the attempt may still fail over (warn level). */
    public function warn(string $message): void;
}
