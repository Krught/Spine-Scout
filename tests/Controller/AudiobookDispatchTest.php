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
 * Audiobook requests route to the torrent pipeline (DispatchTorrentSearch) and are
 * only auto-approved when the Prowlarr + qBittorrent stack is configured.
 */
final class AudiobookDispatchTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');

        $user = new User('reader');
        $user->setPassword('hash-not-checked');
        $this->em->persist($user);

        $this->book = new Book(Book::SOURCE_HARDCOVER, 'dcc', 'Dungeon Crawler Carl');
        $this->book->setAuthor('Matt Dinniman')->setAudiobookAvailable(true);
        $this->book->setMetadataFetchedAt(new \DateTimeImmutable());
        $this->em->persist($this->book);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testConfiguredStackAutoApprovesAndDispatchesTorrentSearch(): void
    {
        $this->enableAutoApprove();
        $this->configureTorrentStack();

        $this->post(['bookId' => $this->book->getId(), 'audiobook' => 1]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(BookRequest::STATUS_APPROVED, $data['status']);
        self::assertStringContainsString('DispatchTorrentSearch', $this->messengerQueueDump());
        self::assertStringNotContainsString('DispatchReleaseSearch', $this->messengerQueueDump());
    }

    public function testUnconfiguredStackLeavesAudiobookPending(): void
    {
        $this->enableAutoApprove();
        // No Prowlarr/qBittorrent rows → torrent stack not ready.

        $this->post(['bookId' => $this->book->getId(), 'audiobook' => 1]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(BookRequest::STATUS_PENDING, $data['status']);
        self::assertSame('', $this->messengerQueueDump(), 'nothing dispatched for a pending request');
    }

    public function testEbookStillDispatchesReleaseSearch(): void
    {
        $this->enableAutoApprove();

        $this->post(['bookId' => $this->book->getId()]); // ebook (no audiobook flag)
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(BookRequest::STATUS_APPROVED, $data['status']);
        self::assertStringContainsString('DispatchReleaseSearch', $this->messengerQueueDump());
    }

    private function enableAutoApprove(): void
    {
        $app = new Integration(Integration::KIND_APP);
        $app->setAuthType(Integration::AUTH_NONE);
        $app->setEnabled(true);
        $app->setAutoApproveRequestsEnabled(true);
        $this->em->persist($app);
        $this->em->flush();
    }

    private function configureTorrentStack(): void
    {
        $prowlarr = new Integration(Integration::KIND_PROWLARR);
        $prowlarr->setAuthType(Integration::AUTH_API_KEY);
        $prowlarr->setBaseUrl('http://prowlarr:9696');
        $prowlarr->setCredentials(['token' => 'k']);
        $prowlarr->setEnabled(true);
        $this->em->persist($prowlarr);

        $qbit = new Integration(Integration::KIND_QBITTORRENT);
        $qbit->setAuthType(Integration::AUTH_BASIC);
        $qbit->setBaseUrl('http://qbittorrent:8080');
        $qbit->setCredentials(['username' => 'admin', 'password' => 'x']);
        $qbit->setEnabled(true);
        $this->em->persist($qbit);
        $this->em->flush();
    }

    private function messengerQueueDump(): string
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT body FROM messenger_messages');

        return implode("\n", $rows);
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
