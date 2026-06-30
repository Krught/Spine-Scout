<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\Integration;
use App\Entity\User;
use App\Repository\BookRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The detail modal's Book/Audiobook toggle creates format-specific requests and reports
 * per-format ownership via /books/metadata.
 */
final class AudiobookRequestTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private BookRequestRepository $requests;
    private Book $book;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->requests = $c->get(BookRequestRepository::class);

        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        // No integrations → a forced metadata refresh is a no-op (no upstream/network call).
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();

        $user = new User('reader');
        $user->setPassword('hash-not-checked');
        $this->em->persist($user);

        $this->book = new Book(Book::SOURCE_HARDCOVER, 'dungeon-crawler-carl', 'Dungeon Crawler Carl');
        $this->book->setAuthor('Matt Dinniman')->setDownloaded(false)->setAudiobookAvailable(true);
        // Stamp as fetched so loadByInternalId doesn't trigger a live upstream refresh in the test.
        $this->book->setMetadataFetchedAt(new \DateTimeImmutable());
        $this->em->persist($this->book);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testAudiobookAndBookAreSeparateRequests(): void
    {
        // Audiobook request.
        $this->post(['bookId' => $this->book->getId(), 'audiobook' => 1]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['audiobook']);
        self::assertFalse($data['alreadyExisted']);

        // Same audiobook request again → dedup.
        $this->post(['bookId' => $this->book->getId(), 'audiobook' => 1]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['alreadyExisted']);

        // A book (ebook) request is a *separate* request for the same work.
        $this->post(['bookId' => $this->book->getId()]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['audiobook']);
        self::assertFalse($data['alreadyExisted']);

        $this->em->clear();
        $book = $this->em->getRepository(Book::class)->find($this->book->getId());
        $all = $this->requests->findBy(['book' => $book]);
        self::assertCount(2, $all, 'one book + one audiobook request');
        $flags = array_map(static fn (BookRequest $r) => $r->isAudiobook(), $all);
        sort($flags);
        self::assertSame([false, true], $flags);
    }

    public function testMetadataReportsPerModeAndAvailability(): void
    {
        $this->client->request('GET', '/books/metadata?id=' . $this->book->getId());
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $book = $data['book'];

        self::assertTrue($book['audiobookAvailable']);
        self::assertArrayHasKey('modes', $book);
        self::assertArrayHasKey('book', $book['modes']);
        self::assertArrayHasKey('audiobook', $book['modes']);
        self::assertFalse($book['modes']['book']['downloaded']);
        self::assertFalse($book['modes']['audiobook']['downloaded']);
        // Narrator / audio length fields are part of the payload (null here — not refreshed upstream).
        self::assertArrayHasKey('narrator', $book);
        self::assertArrayHasKey('audioSeconds', $book);
    }

    public function testOwnedEbookCountsOnlyInBookMode(): void
    {
        // An owned ebook in the library (matched by title|author) is "In Library" for the book
        // edition but NOT the audiobook.
        $owned = new Book(Book::SOURCE_GRIMMORY, 'komga-1', 'Dungeon Crawler Carl');
        $owned->setAuthor('Matt Dinniman')->setDownloaded(true)->setFormat('epub');
        $this->em->persist($owned);
        $this->em->flush();

        $this->client->request('GET', '/books/metadata?id=' . $this->book->getId());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($data['book']['modes']['book']['downloaded'], 'book mode owns the ebook');
        self::assertFalse($data['book']['modes']['audiobook']['downloaded'], 'audiobook mode does not');
    }

    public function testRefreshReturnsBookPayload(): void
    {
        $this->client->request(
            'POST',
            '/books/metadata/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $this->book->getId(), '_csrf_token' => $this->csrfToken()], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Dungeon Crawler Carl', $data['book']['title']);
    }

    public function testRefreshRejectsBadCsrf(): void
    {
        $this->client->request(
            'POST',
            '/books/metadata/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $this->book->getId(), '_csrf_token' => 'nope'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $payload['_csrf_token'] = $this->csrfToken();
        $this->client->request(
            'POST',
            '/requests/create',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/browse');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('meta[name="csrf-token"]')->attr('content');
        self::assertNotEmpty($token);

        return $token;
    }
}
