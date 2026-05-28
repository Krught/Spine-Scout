<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Service\BookMetadataService;
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

    /** @return array<string, mixed> */
    private function serialize(Book $book): array
    {
        // Trending rows live in separate Book entities from the library copy; cross-check
        // by ISBN then title|author so the popup matches the homepage downloaded marker.
        $downloaded = $book->isDownloaded();
        if (!$downloaded && $book->getSource() !== Book::SOURCE_GRIMMORY) {
            if ($book->getIsbn() !== null && isset($this->books->downloadedIsbns()[$book->getIsbn()])) {
                $downloaded = true;
            } else {
                $key = BookRepository::normalizeTitleAuthor($book->getTitle(), $book->getAuthor());
                if ($key !== null && isset($this->books->downloadedTitleAuthorKeys()[$key])) {
                    $downloaded = true;
                }
            }
        }
        $requestStatus = null;
        $user = $this->getUser();
        if ($user instanceof User && $book->getId() !== null) {
            $existing = $this->requests->findOneByUserAndBook($user, $book);
            if ($existing !== null) {
                $requestStatus = $existing->getStatus();
            }
        }
        // Available means the request was fulfilled into the library — surface it as downloaded.
        if ($requestStatus === 'available') {
            $downloaded = true;
            $requestStatus = null;
        }

        return [
            'id'            => $book->getId(),
            'requestStatus' => $requestStatus,
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
            'downloaded'    => $downloaded,
            'externalUrl'   => $book->getExternalUrl(),
            'fetched'       => $book->getMetadataFetchedAt() !== null,
        ];
    }
}
