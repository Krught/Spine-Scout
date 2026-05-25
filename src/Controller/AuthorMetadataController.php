<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Author;
use App\Repository\BookRepository;
use App\Service\AuthorMetadataService;
use App\Service\CoverCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorMetadataController extends AbstractController
{
    public function __construct(
        private readonly AuthorMetadataService $metadata,
        private readonly CoverCache $covers,
        private readonly BookRepository $books,
    ) {
    }

    #[Route('/authors/metadata', name: 'author_metadata', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $source = (string) $request->query->get('source', Author::SOURCE_HARDCOVER);
        $slug = trim((string) $request->query->get('slug', ''));
        if ($slug === '') {
            return new JsonResponse(['error' => 'missing_identifier'], 400);
        }
        if ($source !== Author::SOURCE_HARDCOVER) {
            return new JsonResponse(['error' => 'unknown_source'], 400);
        }

        $author = $this->metadata->loadBySourceAndSlug($source, $slug, [
            'name' => $request->query->get('name'),
            'imageUrl' => $request->query->get('imageUrl'),
            'externalUrl' => $request->query->get('externalUrl'),
        ]);

        return new JsonResponse(['author' => $this->serialize($author)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Author $author): array
    {
        $remoteImage = $author->getImageUrl();
        $libraryKeys = $this->books->downloadedTitleAuthorKeys();
        $books = [];
        foreach ($author->getTopBooks() as $b) {
            $cover = $b['coverUrl'] ?? null;
            $slug = $b['slug'] ?? null;
            $key = BookRepository::normalizeTitleAuthor($b['title'], $author->getName());
            $books[] = [
                'title' => $b['title'],
                'slug' => $slug,
                'coverUrl' => is_string($cover) && $cover !== '' ? $this->covers->proxyUrlForRemote($cover) : null,
                'externalUrl' => is_string($slug) && $slug !== '' ? 'https://hardcover.app/books/' . $slug : null,
                'downloaded' => $key !== null && isset($libraryKeys[$key]),
            ];
        }
        return [
            'name'        => $author->getName(),
            'bio'         => $author->getBio(),
            'imageUrl'    => $remoteImage !== null && $remoteImage !== ''
                ? $this->covers->proxyUrlForRemote($remoteImage)
                : null,
            'externalUrl' => $author->getExternalUrl(),
            'location'    => $author->getLocation(),
            'bornYear'    => $author->getBornYear(),
            'deathYear'   => $author->getDeathYear(),
            'booksCount'  => $author->getBooksCount(),
            'usersCount'  => $author->getUsersCount(),
            'topBooks'    => $books,
            'fetched'     => $author->getMetadataFetchedAt() !== null,
        ];
    }
}
