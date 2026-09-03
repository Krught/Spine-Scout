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
        // A disabled integration, an un-due interval or the operator's "pause freeleech sweep"
        // switch all come back as skipped — the refresher owns those gates so the scheduled run,
        // the settings force button and `spinescout:mam:probe` cannot diverge — and a skip has
        // nothing worth an info line (the refresher already logs the paused case itself).
        $summary = $this->refresher->refresh($message->force);
        if ($summary['skipped']) {
            return;
        }

        $this->logger->info('MyAnonamouse freeleech refresh ran', $summary);
    }
}
