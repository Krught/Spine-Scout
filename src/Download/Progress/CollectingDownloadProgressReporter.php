<?php

declare(strict_types=1);

namespace App\Download\Progress;

/**
 * In-memory reporter that captures the workflow's progress trail instead of
 * writing it anywhere. Used by the dev direct-download link test so the exact
 * stages a real download went through — handed the link to the bypasser,
 * cleared the challenge, found the real partner link, streamed the file — can be
 * returned and shown back to the operator, proving the test ran the production
 * workflow rather than a naive fetch.
 */
final class CollectingDownloadProgressReporter implements DownloadProgressReporter
{
    /** @var list<array{level: string, message: string}> */
    private array $entries = [];

    public function step(string $message): void
    {
        $this->entries[] = ['level' => 'info', 'message' => $message];
    }

    public function warn(string $message): void
    {
        $this->entries[] = ['level' => 'warn', 'message' => $message];
    }

    /** @return list<array{level: string, message: string}> */
    public function entries(): array
    {
        return $this->entries;
    }
}
