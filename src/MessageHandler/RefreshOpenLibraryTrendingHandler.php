<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Book;
use App\Entity\BookSectionEntry;
use App\Entity\Integration;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Message\RefreshOpenLibraryTrending;
use App\Repository\BookRepository;
use App\Repository\BookSectionEntryRepository;
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
        private readonly BookRepository $books,
        private readonly BookSectionEntryRepository $sectionEntries,
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

        $now = new \DateTimeImmutable();

        try {
            $books = $this->client->fetchTrending();
            $ids = [];
            foreach ($books as $b) {
                $slug = $this->slugFromExternalUrl($b->externalUrl);
                if ($slug === null) {
                    continue;
                }
                $book = $this->books->upsertMetadataBook(
                    source: Book::SOURCE_OPENLIBRARY,
                    externalId: $slug,
                    title: $b->title,
                    author: $b->author,
                    externalUrl: $b->externalUrl,
                    coverUrl: $b->coverUrl,
                    rawIsbns: $b->isbns,
                    now: $now,
                );
                if ($book->getId() === null) {
                    $this->em->flush();
                }
                $ids[] = (int) $book->getId();
            }
            $this->em->flush();
            $this->sectionEntries->replaceSection(Book::SOURCE_OPENLIBRARY, BookSectionEntry::SECTION_TRENDING, $ids, $now);

            $integration->setLastSyncAt(new \DateTimeImmutable());
            $integration->setLastError(null);
            $integration->touch();
        } catch (OpenLibraryException $e) {
            $this->logger->warning('Open Library trending refresh failed', ['error' => $e->getMessage()]);
            $integration->setLastError($e->getMessage());
        }

        $this->em->flush();
    }

    private function slugFromExternalUrl(?string $externalUrl): ?string
    {
        if ($externalUrl === null || $externalUrl === '') {
            return null;
        }
        $path = parse_url($externalUrl, PHP_URL_PATH) ?: '';
        return preg_match('~/works/(OL[A-Z0-9]+W)~', $path, $m) ? $m[1] : null;
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
