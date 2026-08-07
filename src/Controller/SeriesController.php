<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\IntegrationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON backend for the book modal's "Request series" panel: lists every book in a
 * named series (via Hardcover) stamped with library ownership and the current user's
 * request status, so the panel can pre-disable what's already owned or requested.
 */
#[IsGranted('ROLE_USER')]
final class SeriesController extends AbstractController
{
    public function __construct(
        private readonly HardcoverClient $hardcover,
        private readonly IntegrationRepository $integrations,
        private readonly BookRepository $books,
        private readonly BookRequestRepository $requests,
    ) {
    }

    #[Route('/series/books', name: 'series_books', methods: ['GET'])]
    public function books(Request $request): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'missing_name'], 400);
        }
        $audiobook = (string) $request->query->get('audiobook', '0') === '1';

        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasCredentials()) {
            return new JsonResponse(['ok' => false, 'error' => 'hardcover_unavailable'], 503);
        }

        try {
            $result = $this->hardcover->fetchSeriesBooks($integration, $name);
        } catch (HardcoverException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        /** @var User $user */
        $user = $this->getUser();
        // Ownership/status is format-aware: the panel is opened in book or audiobook mode
        // and only that format's owned copies / requests should disable rows.
        $ownedIsbns = $this->books->downloadedIsbns($audiobook);
        $ownedTitleAuthor = $this->books->downloadedTitleAuthorKeys($audiobook);
        $statusMaps = $this->requests->statusMapsForUser($user, $audiobook);

        $out = [];
        foreach ($result['books'] as $book) {
            // Client isbns are already normalized (extractIsbns runs normalizeIsbn), matching
            // the keys of both the owned-isbn map and the request-status isbn map.
            $owned = false;
            $requestStatus = null;
            foreach ($book['isbns'] as $isbn) {
                if (isset($ownedIsbns[$isbn])) {
                    $owned = true;
                }
                if ($requestStatus === null && isset($statusMaps['isbns'][$isbn])) {
                    $requestStatus = $statusMaps['isbns'][$isbn];
                }
            }
            $key = BookRepository::normalizeTitleAuthor($book['title'], $book['author']);
            if ($key !== null) {
                $owned = $owned || isset($ownedTitleAuthor[$key]);
                $requestStatus ??= $statusMaps['titleAuthor'][$key] ?? null;
            }

            $out[] = [
                'slug'          => $book['slug'],
                'title'         => $book['title'],
                'author'        => $book['author'],
                'coverUrl'      => $book['coverUrl'],
                'position'      => $book['position'],
                'owned'         => $owned,
                'requestStatus' => $requestStatus,
            ];
        }

        return new JsonResponse(['ok' => true, 'series' => $result['series'], 'books' => $out]);
    }
}
