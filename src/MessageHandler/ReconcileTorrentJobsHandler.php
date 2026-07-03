<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Torrent\TorrentJobReconciler;
use App\Entity\Integration;
use App\Message\ReconcileTorrents;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReconcileTorrentJobsHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly TorrentJobReconciler $reconciler,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileTorrents $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        if ($integration === null || !$integration->isEnabled()) {
            return;
        }

        $intervalHours = $this->integrations->getTorrentClientConfig()->reconcileIntervalHours;
        if (!$message->force && $intervalHours <= 0) {
            return; // Automatic reconcile disabled; the manual button still bypasses this.
        }

        if (!$message->force && !$this->isDue($integration, $intervalHours)) {
            return;
        }

        $result = $this->reconciler->reconcile(apply: true);
        $integration->setLastSyncAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($result->reconciled > 0 || $result->gone > 0) {
            $this->logger->info('Torrent job reconcile ran', [
                'reconciled' => $result->reconciled,
                'skipped'    => $result->skipped,
                'gone'       => $result->gone,
            ]);
        }
    }

    private function isDue(Integration $integration, int $intervalHours): bool
    {
        $last = $integration->getLastSyncAt();
        if ($last === null) {
            return true;
        }
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $last->getTimestamp();

        return $elapsed >= $intervalHours * 3600;
    }
}
