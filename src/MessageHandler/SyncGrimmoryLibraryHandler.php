<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryException;
use App\Integration\Grimmory\GrimmoryLibrarySync;
use App\Message\SyncGrimmoryLibrary;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncGrimmoryLibraryHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly GrimmoryLibrarySync $sync,
        private readonly CoverCache $covers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncGrimmoryLibrary $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            return;
        }

        if (!$message->force && !$this->isDue($integration)) {
            return;
        }

        try {
            $result = $this->sync->sync($integration);
        } catch (GrimmoryException $e) {
            $this->logger->warning('Grimmory sync failed', ['error' => $e->getMessage()]);
            return;
        }

        if ($result->newExternalIds !== []) {
            $summary = $this->covers->warmAll([], $result->newExternalIds);
            $this->logger->info('Grimmory cover prewarm complete', $summary);
        }
    }

    private function isDue(Integration $integration): bool
    {
        $last = $integration->getLastSyncAt();
        if ($last === null) {
            return true;
        }
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $last->getTimestamp();
        return $elapsed >= $integration->getSyncIntervalMinutes() * 60;
    }
}
