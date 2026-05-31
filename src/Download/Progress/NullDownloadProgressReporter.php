<?php

declare(strict_types=1);

namespace App\Download\Progress;

/** No-op reporter: the default when a caller supplies no progress sink. */
final class NullDownloadProgressReporter implements DownloadProgressReporter
{
    public function step(string $message): void
    {
    }

    public function warn(string $message): void
    {
    }
}
