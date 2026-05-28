<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TouchBooksSeen;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TouchBooksSeenHandler
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(TouchBooksSeen $message): void
    {
        $ids = [];
        foreach ($message->bookIds as $id) {
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE books SET last_seen_at = :now WHERE id IN (:ids)',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'ids' => array_keys($ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }
}
