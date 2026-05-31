<?php

declare(strict_types=1);

namespace App\Download\Progress;

use App\Download\FulfillmentLog;

/**
 * Writes download progress to the Download Activity monitor via FulfillmentLog,
 * tagged with the book subject and a per-link prefix ("Link 6/8") so the stages
 * of one candidate attempt read as a coherent trail.
 */
final class FulfillmentDownloadProgressReporter implements DownloadProgressReporter
{
    public function __construct(
        private readonly FulfillmentLog $log,
        private readonly ?string $subject,
        private readonly string $prefix,
    ) {
    }

    public function step(string $message): void
    {
        $this->log->info($this->line($message), $this->subject);
    }

    public function warn(string $message): void
    {
        $this->log->warn($this->line($message), $this->subject);
    }

    private function line(string $message): string
    {
        return $this->prefix === '' ? $message : $this->prefix . ': ' . $message;
    }
}
