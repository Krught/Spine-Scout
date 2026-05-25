<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Author;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Repository\AuthorRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AuthorMetadataService
{
    public function __construct(
        private readonly AuthorRepository $authors,
        private readonly IntegrationRepository $integrations,
        private readonly EntityManagerInterface $em,
        private readonly HardcoverClient $hardcover,
    ) {
    }

    public function loadBySourceAndSlug(string $source, string $slug, array $seed = []): Author
    {
        $author = $this->authors->findOneBySourceAndSlug($source, $slug);
        if ($author === null) {
            $author = new Author($source, $slug, $seed['name'] ?? $slug);
            if (!empty($seed['imageUrl'])) {
                $author->setImageUrl((string) $seed['imageUrl']);
            }
            if (!empty($seed['externalUrl'])) {
                $author->setExternalUrl((string) $seed['externalUrl']);
            }
            $this->em->persist($author);
        }
        if ($author->getMetadataFetchedAt() === null) {
            $this->refresh($author);
        }
        $this->em->flush();
        return $author;
    }

    private function refresh(Author $author): void
    {
        try {
            $data = match ($author->getSource()) {
                Author::SOURCE_HARDCOVER => $this->fetchFromHardcover($author->getSlug()),
                default => null,
            };
        } catch (HardcoverException) {
            return;
        }
        if ($data === null) {
            return;
        }
        $this->apply($author, $data);
    }

    private function fetchFromHardcover(string $slug): array
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null || !$integration->isEnabled()) {
            throw new HardcoverException('Hardcover integration is not enabled.');
        }
        return $this->hardcover->fetchAuthorMetadataBySlug($integration, $slug);
    }

    private function apply(Author $author, array $data): void
    {
        if (!empty($data['name'])) {
            $author->setName((string) $data['name']);
        }
        if (array_key_exists('bio', $data)) {
            $author->setBio($data['bio'] !== null ? (string) $data['bio'] : null);
        }
        if (array_key_exists('imageUrl', $data) && $data['imageUrl'] !== null) {
            $author->setImageUrl((string) $data['imageUrl']);
        }
        if (array_key_exists('externalUrl', $data) && $data['externalUrl'] !== null) {
            $author->setExternalUrl((string) $data['externalUrl']);
        }
        if (array_key_exists('location', $data)) {
            $author->setLocation($data['location'] !== null ? (string) $data['location'] : null);
        }
        if (array_key_exists('bornYear', $data)) {
            $author->setBornYear(is_int($data['bornYear']) ? $data['bornYear'] : null);
        }
        if (array_key_exists('deathYear', $data)) {
            $author->setDeathYear(is_int($data['deathYear']) ? $data['deathYear'] : null);
        }
        if (array_key_exists('booksCount', $data)) {
            $author->setBooksCount(is_int($data['booksCount']) ? $data['booksCount'] : null);
        }
        if (array_key_exists('usersCount', $data)) {
            $author->setUsersCount(is_int($data['usersCount']) ? $data['usersCount'] : null);
        }
        if (isset($data['topBooks']) && is_array($data['topBooks'])) {
            $clean = [];
            foreach ($data['topBooks'] as $b) {
                if (is_array($b) && !empty($b['title']) && is_string($b['title'])) {
                    $slug = $b['slug'] ?? null;
                    $cover = $b['coverUrl'] ?? null;
                    $clean[] = [
                        'title' => $b['title'],
                        'slug' => is_string($slug) ? $slug : null,
                        'coverUrl' => is_string($cover) && $cover !== '' ? $cover : null,
                    ];
                }
            }
            $author->setTopBooks($clean);
        }
        $author->setMetadataFetchedAt(new \DateTimeImmutable());
    }
}
