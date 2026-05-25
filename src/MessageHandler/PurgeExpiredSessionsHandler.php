<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredSessions;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeExpiredSessionsHandler
{
    public function __construct(
        private readonly PdoSessionHandler $handler,
        private readonly int $maxLifetime,
    ) {
    }

    public function __invoke(PurgeExpiredSessions $message): void
    {
        $this->handler->gc($this->maxLifetime);
    }
}
