<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Service\BookMetadataService;
use App\Service\BookRecommendationService;
use App\Support\AudioFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BookMetadataController extends AbstractController
{
    public function __construct(
        private readonly BookMetadataService $metadata,
        private readonly BookRepository $books,
        private readonly BookRequestRepository $requests,
        private readonly BookRecommendationService $recommendations,
    ) {
    }

    #[Route('/books/metadata', name: 'book_metadata', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $id = $request->query->get('id');
        if (is_string($id) && ctype_digit($id)) {
            $book = $this->metadata->loadByInternalId((int) $id);
            if ($book === null) {
                return new JsonResponse(['error' => 'not_found'], 404);
            }
            return new JsonResponse(['book' => $this->serialize($book)]);
        }

        $source = (string) $request->query->get('source', '');
        $externalId = (string) $request->query->get('externalId', '');
        if ($source === '' || $externalId === '') {
            return new JsonResponse(['error' => 'missing_identifier'], 400);
        }
        if (!in_array($source, [Book::SOURCE_GRIMMORY, Book::SOURCE_HARDCOVER, Book::SOURCE_OPENLIBRARY], true)) {
            return new JsonResponse(['error' => 'unknown_source'], 400);
        }

        $book = $this->metadata->loadBySourceAndExternalId($source, $externalId, [
            'title' => $request->query->get('title'),
            'author' => $request->query->get('author'),
            'externalUrl' => $request->query->get('externalUrl'),
        ]);
        return new JsonResponse(['book' => $this->serialize($book)]);
    }

    /**
     * Async narrator/runtime backfill for audiobooks. The modal opens instantly on cached data
     * (show() never blocks on this) and fires this in the background when the user is on the
     * audiobook tab; it patches the audio facts in once we have them. Returns just the audio
     * fields — a no-op upstream when they're already cached (see {@see BookMetadataService::ensureAudioMetadata()}).
     */
    #[Route('/books/metadata/audio', name: 'book_metadata_audio', methods: ['GET'])]
    public function audio(Request $request): JsonResponse
    {
        $id = $request->query->get('id');
        if (is_string($id) && ctype_digit($id)) {
            $book = $this->books->find((int) $id);
        } else {
            $source = (string) $request->query->get('source', '');
            $externalId = (string) $request->query->get('externalId', '');
            if ($source === '' || $externalId === '') {
                return new JsonResponse(['error' => 'missing_identifier'], 400);
            }
            $book = $this->books->findOneBySourceAndExternalId($source, $externalId);
        }
        if ($book === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $this->metadata->ensureAudioMetadata($book);

        return new JsonResponse(['audio' => [
            'audiobookAvailable' => $book->isAudiobookAvailable(),
            'narrator'           => $book->getNarrator(),
            'audioSeconds'       => $book->getAudioSeconds(),
        ]]);
    }

    /**
     * Force a fresh upstream metadata fetch (the modal's manual "refresh" button), then return
     * the same payload as show() so the modal can re-render in place.
     */
    #[Route('/books/metadata/refresh', name: 'book_metadata_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }
        if (!$this->isCsrfTokenValid('book-request', (string) ($payload['_csrf_token'] ?? ''))) {
            return new JsonResponse(['error' => 'invalid_csrf'], 403);
        }

        $rawId = $payload['id'] ?? null;
        if (is_int($rawId) || (is_string($rawId) && ctype_digit((string) $rawId))) {
            $book = $this->books->find((int) $rawId);
            if ($book === null) {
                return new JsonResponse(['error' => 'not_found'], 404);
            }
        } else {
            $source = isset($payload['source']) ? (string) $payload['source'] : '';
            $externalId = isset($payload['externalId']) ? (string) $payload['externalId'] : '';
            if ($source === '' || $externalId === '') {
                return new JsonResponse(['error' => 'missing_identifier'], 400);
            }
            if (!in_array($source, [Book::SOURCE_GRIMMORY, Book::SOURCE_HARDCOVER, Book::SOURCE_OPENLIBRARY], true)) {
                return new JsonResponse(['error' => 'unknown_source'], 400);
            }
            $book = $this->metadata->loadBySourceAndExternalId($source, $externalId, [
                'title' => $payload['title'] ?? null,
                'author' => $payload['author'] ?? null,
                'externalUrl' => $payload['externalUrl'] ?? null,
            ]);
        }

        $this->metadata->forceRefresh($book);

        return new JsonResponse(['book' => $this->serialize($book)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Book $book): array
    {
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;

        // Ownership/request status is computed per format so the modal's Book/Audiobook toggle
        // reflects the right edition. Top-level fields keep book-mode values for back-compat.
        $bookMode = $this->ownershipForMode($book, false, $user);
        $audioMode = $this->ownershipForMode($book, true, $user);

        return [
            'id'            => $book->getId(),
            'requestStatus' => $bookMode['requestStatus'],
            'title'         => $book->getTitle(),
            'author'        => $book->getAuthor(),
            'publisher'     => $book->getPublisher(),
            'publishedDate' => $book->getPublishedDate(),
            'language'      => $book->getLanguage(),
            'isbn'          => $book->getIsbn(),
            'description'   => $book->getDescription(),
            'genres'        => $book->getGenres(),
            'series'        => $book->getSeries(),
            'seriesIndex'   => $book->getSeriesIndex(),
            'seriesTotal'   => $book->getSeriesTotal(),
            'downloaded'    => $bookMode['downloaded'],
            'externalUrl'   => $book->getExternalUrl(),
            'fetched'       => $book->getMetadataFetchedAt() !== null,
            // Whether an audiobook edition exists upstream — drives the modal's Book/Audiobook toggle.
            'audiobookAvailable' => $book->isAudiobookAvailable(),
            'format'        => $book->getFormat(),
            'narrator'      => $book->getNarrator(),
            'audioSeconds'  => $book->getAudioSeconds(),
            'modes'         => ['book' => $bookMode, 'audiobook' => $audioMode],
            // Internal id to seed "More like this", or null when the book can't be recommended
            // against (no resolvable Hardcover record). Drives the modal button's visibility.
            'recommendSeed' => $this->recommendations->recommendSeedRef($book),
        ];
    }

    /**
     * Downloaded/request status for one format. Library ownership is format-aware (an owned audio
     * file counts only in audiobook mode); the request lookup is scoped to that format too.
     *
     * @return array{downloaded: bool, requestStatus: ?string}
     */
    private function ownershipForMode(Book $book, bool $audiobook, ?User $user): array
    {
        // The book's own row (library-synced) IS the owned file — it counts for the mode whose
        // format matches. Trending/metadata rows cross-check the library by ISBN then title|author.
        if ($book->isDownloaded() && $book->getSource() === Book::SOURCE_GRIMMORY) {
            $downloaded = AudioFormat::isAudio($book->getFormat()) === $audiobook;
        } else {
            $downloaded = false;
            $isbn = $book->getIsbn();
            if ($isbn !== null && isset($this->books->downloadedIsbns($audiobook)[$isbn])) {
                $downloaded = true;
            } else {
                $key = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());
                if ($key !== null && isset($this->books->downloadedTitleAuthorKeys($audiobook)[$key])) {
                    $downloaded = true;
                }
            }
        }

        $requestStatus = null;
        if ($user instanceof User && $book->getId() !== null) {
            $existing = $this->requests->findOneByUserAndBook($user, $book, $audiobook);
            if ($existing !== null) {
                $requestStatus = $existing->getStatus();
                // Downloaded but not yet imported — distinct pseudo-status for a "Downloaded" badge.
                if ($requestStatus === BookRequest::STATUS_APPROVED && $existing->getDeliveryStatus() === DownloadJob::STATUS_COMPLETE) {
                    $requestStatus = 'downloaded';
                }
            }
        }
        // Available means the request was fulfilled into the library — surface it as downloaded.
        if ($requestStatus === 'available') {
            $downloaded = true;
            $requestStatus = null;
        }

        return ['downloaded' => $downloaded, 'requestStatus' => $requestStatus];
    }
}
