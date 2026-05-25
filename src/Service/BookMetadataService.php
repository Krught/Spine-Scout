<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryClient;
use App\Integration\Grimmory\GrimmoryException;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Integration\OpenLibrary\OpenLibraryClient;
use App\Integration\OpenLibrary\OpenLibraryException;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists upstream lookup results so subsequent clicks on the same book are served from the DB.
 */
final class BookMetadataService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly IntegrationRepository $integrations,
        private readonly EntityManagerInterface $em,
        private readonly GrimmoryClient $grimmory,
        private readonly HardcoverClient $hardcover,
        private readonly OpenLibraryClient $openLibrary,
    ) {
    }

    /**
     * Seed fields are used when creating a new row so the popup shows something
     * while the upstream lookup runs.
     *
     * @param array{title?: ?string, author?: ?string, coverUrl?: ?string, externalUrl?: ?string} $seed
     */
    public function loadBySourceAndExternalId(string $source, string $externalId, array $seed = []): Book
    {
        $book = $this->books->findOneBySourceAndExternalId($source, $externalId);
        if ($book === null) {
            $book = new Book($source, $externalId, $seed['title'] ?? '(untitled)');
            $book->setDownloaded(false);
            if (!empty($seed['author'])) {
                $book->setAuthor((string) $seed['author']);
            }
            if (!empty($seed['externalUrl'])) {
                $book->setExternalUrl((string) $seed['externalUrl']);
            }
            $this->em->persist($book);
        }
        if ($book->getMetadataFetchedAt() === null) {
            $this->refresh($book);
        }
        $this->em->flush();
        return $book;
    }

    public function loadByInternalId(int $id): ?Book
    {
        $book = $this->books->find($id);
        if ($book === null) {
            return null;
        }
        if ($book->getMetadataFetchedAt() === null) {
            $this->refresh($book);
            $this->em->flush();
        }
        return $book;
    }

    /**
     * Silent on upstream failure: row stays untouched (metadataFetchedAt null) so a future click retries.
     */
    private function refresh(Book $book): void
    {
        try {
            $data = match ($book->getSource()) {
                Book::SOURCE_GRIMMORY    => $this->fetchFromGrimmory($book->getExternalId()),
                Book::SOURCE_HARDCOVER   => $this->fetchFromHardcover($book->getExternalId()),
                Book::SOURCE_OPENLIBRARY => $this->openLibrary->fetchBookMetadataByKey($book->getExternalId()),
                default => null,
            };
        } catch (GrimmoryException | HardcoverException | OpenLibraryException) {
            return;
        }
        if ($data === null) {
            return;
        }
        $this->apply($book, $data);
    }

    /** @return array<string, mixed> */
    private function fetchFromGrimmory(string $externalId): array
    {
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            throw new GrimmoryException('Grimmory integration is not enabled.');
        }
        return $this->grimmory->fetchBookMetadata($integration, $externalId);
    }

    /** @return array<string, mixed> */
    private function fetchFromHardcover(string $externalId): array
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled()) {
            throw new HardcoverException('Hardcover integration is not enabled.');
        }
        return $this->hardcover->fetchBookMetadataBySlug($integration, $externalId);
    }

    /** @param array<string, mixed> $data */
    private function apply(Book $book, array $data): void
    {
        if (!empty($data['title'])) {
            $book->setTitle((string) $data['title']);
        }
        if (!empty($data['author'])) {
            $book->setAuthor((string) $data['author']);
        }
        if (array_key_exists('publisher', $data)) {
            $book->setPublisher($data['publisher'] !== null ? (string) $data['publisher'] : null);
        }
        if (array_key_exists('publishedDate', $data)) {
            $book->setPublishedDate($data['publishedDate'] !== null ? (string) $data['publishedDate'] : null);
        }
        if (array_key_exists('language', $data)) {
            $book->setLanguage($data['language'] !== null ? (string) $data['language'] : null);
        }
        if (array_key_exists('description', $data)) {
            $book->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (!empty($data['isbn']) && $book->getIsbn() === null) {
            $book->setIsbn((string) $data['isbn']);
        }
        if (isset($data['genres']) && is_array($data['genres'])) {
            $book->setGenres(array_values(array_filter($data['genres'], 'is_string')));
        }
        if (array_key_exists('series', $data) && $data['series'] !== null) {
            $book->setSeries((string) $data['series']);
        }
        if (array_key_exists('seriesIndex', $data) && $data['seriesIndex'] !== null) {
            $book->setSeriesIndex((string) $data['seriesIndex']);
        }
        if (array_key_exists('seriesTotal', $data)) {
            $book->setSeriesTotal(is_int($data['seriesTotal']) ? $data['seriesTotal'] : null);
        }
        $book->setMetadataFetchedAt(new \DateTimeImmutable());
    }
}
