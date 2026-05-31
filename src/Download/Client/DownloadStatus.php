<?php

declare(strict_types=1);

namespace App\Download\Client;

/**
 * Status snapshot returned by DownloadClientInterface::getStatus().
 * Immutable.
 */
final class DownloadStatus
{
    public const STATE_QUEUED      = 'queued';
    public const STATE_DOWNLOADING = 'downloading';
    public const STATE_COMPLETE    = 'complete';
    public const STATE_ERROR       = 'error';
    public const STATE_PAUSED      = 'paused';
    public const STATE_SEEDING     = 'seeding';
    public const STATE_UNKNOWN     = 'unknown';

    public const STATES = [
        self::STATE_QUEUED,
        self::STATE_DOWNLOADING,
        self::STATE_COMPLETE,
        self::STATE_ERROR,
        self::STATE_PAUSED,
        self::STATE_SEEDING,
        self::STATE_UNKNOWN,
    ];

    public function __construct(
        public readonly string $state,
        public readonly float $progress,
        public readonly ?string $message = null,
        public readonly ?string $filePath = null,
        public readonly ?int $downloadSpeedBytesPerSec = null,
        public readonly ?int $etaSeconds = null,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(state: self::STATE_ERROR, progress: 0.0, message: $message);
    }

    public function isComplete(): bool
    {
        return $this->state === self::STATE_COMPLETE;
    }

    public function isTerminal(): bool
    {
        return $this->state === self::STATE_COMPLETE || $this->state === self::STATE_ERROR;
    }
}
