<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Integration;
use App\Message\PurgeStaleBooks;
use App\Repository\IntegrationRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeStaleBooksHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeStaleBooks $message): void
    {
        // The threshold is held on whichever metadata integration the admin has touched most
        // recently; in practice this is Hardcover. We pull from Hardcover first so a user that
        // never opened OpenLibrary still gets the configured value.
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER)
            ?? $this->integrations->findByKind(Integration::KIND_OPENLIBRARY);
        $days = $integration?->getBookPurgeThresholdDays() ?? 30;

        $sql = <<<SQL
DELETE FROM books
WHERE downloaded = false
  AND last_seen_at < NOW() - (:days || ' days')::interval
  AND id NOT IN (SELECT book_id FROM book_requests)
  AND id NOT IN (SELECT book_id FROM book_section_entries)
SQL;
        $count = (int) $this->connection->executeStatement($sql, ['days' => (string) $days]);
        $this->logger->info('PurgeStaleBooks complete', ['threshold_days' => $days, 'deleted' => $count, 'force' => $message->force]);
    }
}
