<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Message\RefreshHardcoverTrending;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Refreshes every Hardcover-backed homepage shelf in one pass: trending,
 * new releases, upcoming, staff picks, and popular authors. Each shelf is
 * cached under its own key in Integration.cacheData so one failing query
 * doesn't blank the others — the homepage shows an empty state for the
 * affected row only.
 */
#[AsMessageHandler]
final class RefreshHardcoverTrendingHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly HardcoverClient $client,
        private readonly EntityManagerInterface $em,
        private readonly CoverCache $covers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshHardcoverTrending $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasCredentials()) {
            return;
        }

        if (!$message->force && !$this->isDue($integration)) {
            return;
        }

        $cache = $integration->getCacheData();
        $errors = [];

        $shelves = [
            'trending'     => fn () => $this->client->fetchTrending($integration),
            'new_releases' => fn () => $this->client->fetchNewReleases($integration),
            'upcoming'     => fn () => $this->client->fetchUpcoming($integration),
            'staff_picks'  => fn () => $this->client->fetchStaffPicks($integration),
        ];

        foreach ($shelves as $key => $fetch) {
            try {
                $cache[$key] = [
                    'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'books' => array_map(static fn ($b) => $b->toArray(), $fetch()),
                ];
            } catch (HardcoverException $e) {
                $errors[] = $key . ': ' . $e->getMessage();
                $this->logger->warning('Hardcover shelf refresh failed', ['shelf' => $key, 'error' => $e->getMessage()]);
            }
        }

        try {
            $cache['popular_authors'] = [
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'authors' => array_map(static fn ($a) => $a->toArray(), $this->client->fetchPopularAuthors($integration)),
            ];
        } catch (HardcoverException $e) {
            $errors[] = 'popular_authors: ' . $e->getMessage();
            $this->logger->warning('Hardcover popular authors refresh failed', ['error' => $e->getMessage()]);
        }

        // Prewarm the Genre tag vocabulary so user-facing /browse/search?type=genre never pays
        // the ~1000-row fetch. The client itself owns the cache.app entry + 24h TTL; we just
        // make sure the entry exists and is fresh every sync cycle.
        try {
            $tags = $this->client->fetchGenreTags($integration, forceRefresh: true);
            $cache['genres'] = [
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'count'      => count($tags),
            ];
        } catch (HardcoverException $e) {
            $errors[] = 'genres: ' . $e->getMessage();
            $this->logger->warning('Hardcover genre vocabulary refresh failed', ['error' => $e->getMessage()]);
        }

        $integration->setCacheData($cache);
        $integration->setLastSyncAt(new \DateTimeImmutable());
        $integration->setLastError($errors === [] ? null : implode('; ', $errors));
        $integration->touch();

        $this->em->flush();

        // Pre-warm covers for everything we just refreshed so the first user
        // request hits the on-disk WebP. Failures here are non-fatal; the
        // proxy will fall back to fetching on demand.
        $urls = $this->collectCoverUrls($cache);
        if ($urls !== []) {
            $summary = $this->covers->warmAll($urls);
            $this->logger->info('Hardcover cover prewarm complete', $summary);
        }
    }

    /**
     * Pull every cover URL out of the freshly-refreshed cache payload — shelf
     * books, popular author photos, and each author's topBooks.
     *
     * @param array<string, mixed> $cache
     * @return list<string>
     */
    private function collectCoverUrls(array $cache): array
    {
        $urls = [];
        foreach (['trending', 'new_releases', 'upcoming', 'staff_picks'] as $shelf) {
            foreach ($cache[$shelf]['books'] ?? [] as $book) {
                if (!empty($book['coverUrl'])) { $urls[$book['coverUrl']] = true; }
            }
        }
        foreach ($cache['popular_authors']['authors'] ?? [] as $author) {
            if (!empty($author['coverUrl'])) { $urls[$author['coverUrl']] = true; }
            foreach ($author['topBooks'] ?? [] as $top) {
                if (!empty($top['coverUrl'])) { $urls[$top['coverUrl']] = true; }
            }
        }
        return array_keys($urls);
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
