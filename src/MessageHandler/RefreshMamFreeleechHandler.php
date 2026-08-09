<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Integration\MyAnonamouse\MamFreeleechRefresher;
use App\Message\RefreshMamFreeleech;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshMamFreeleechHandler
{
    public function __construct(
        private readonly MamFreeleechRefresher $refresher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshMamFreeleech $message): void
    {
        $summary = $this->refresher->refresh($message->force);
        if ($summary['skipped']) {
            return;
        }

        $this->logger->info('MyAnonamouse freeleech refresh ran', $summary);
    }
}
