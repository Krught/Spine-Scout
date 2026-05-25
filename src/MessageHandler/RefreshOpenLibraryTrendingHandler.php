<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Integration;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Message\RefreshOpenLibraryTrending;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshOpenLibraryTrendingHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly OpenLibraryClient $client,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshOpenLibraryTrending $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_OPENLIBRARY);
        if ($integration === null || !$integration->isEnabled()) {
            return;
        }

        if (!$message->force && !$this->isDue($integration)) {
            return;
        }

        try {
            $books = $this->client->fetchTrending();
            $cache = $integration->getCacheData();
            $cache['trending'] = [
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'books' => array_map(static fn ($b) => $b->toArray(), $books),
            ];
            $integration->setCacheData($cache);
            $integration->setLastSyncAt(new \DateTimeImmutable());
            $integration->setLastError(null);
            $integration->touch();
        } catch (OpenLibraryException $e) {
            $this->logger->warning('Open Library trending refresh failed', ['error' => $e->getMessage()]);
            $integration->setLastError($e->getMessage());
        }

        $this->em->flush();
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
