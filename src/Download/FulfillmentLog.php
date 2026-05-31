<?php

declare(strict_types=1);

namespace App\Download;

use App\Entity\FulfillmentEvent;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Appends human-readable lines to the fulfillment activity log (and mirrors them
 * to the application logger). Writes go through a direct DBAL insert rather than
 * the ORM so logging never flushes a handler's half-built unit of work.
 */
final class FulfillmentLog
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function info(string $message, ?string $subject = null): void
    {
        $this->record(FulfillmentEvent::LEVEL_INFO, $message, $subject);
    }

    public function warn(string $message, ?string $subject = null): void
    {
        $this->record(FulfillmentEvent::LEVEL_WARN, $message, $subject);
    }

    public function error(string $message, ?string $subject = null): void
    {
        $this->record(FulfillmentEvent::LEVEL_ERROR, $message, $subject);
    }

    /**
     * Delete activity entries older than $maxAgeSeconds (default 2h). Keeps the
     * dev monitor showing only recent activity. Returns the number of rows
     * removed; never throws (logging/pruning must not break the pipeline).
     */
    public function pruneOlderThan(int $maxAgeSeconds = 7200): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$maxAgeSeconds} seconds");

        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM fulfillment_events WHERE created_at < :cutoff',
                ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to prune fulfillment events', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private function record(string $level, string $message, ?string $subject): void
    {
        try {
            $this->connection->insert('fulfillment_events', [
                'level'      => $level,
                'message'    => $message,
                'subject'    => $subject,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never let logging break the pipeline.
            $this->logger->warning('Failed to write fulfillment event', ['error' => $e->getMessage()]);
        }

        $context = $subject !== null ? ['subject' => $subject] : [];
        match ($level) {
            FulfillmentEvent::LEVEL_ERROR => $this->logger->error('[fulfillment] ' . $message, $context),
            FulfillmentEvent::LEVEL_WARN  => $this->logger->warning('[fulfillment] ' . $message, $context),
            default                       => $this->logger->info('[fulfillment] ' . $message, $context),
        };
    }
}
