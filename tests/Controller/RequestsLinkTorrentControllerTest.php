<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Download\Client\QbittorrentDownloadClient;
use App\Download\Client\TorrentClientSettings;
use App\Download\Torrent\TorrentClientConfig;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers the manual request→torrent linking endpoints: the rescue path for a
 * torrent the download client accepted but whose job lost its hash (or that was
 * added outside the app). The options endpoint lists the client's torrents with
 * already-linked ones flagged; the link endpoint stamps a DOWNLOADING job with
 * the picked hash so the torrent poller finalizes it like an automatic grab.
 */
final class RequestsLinkTorrentControllerTest extends WebTestCase
{
    private const HASH_A = '3601266b0873bfc80fd1f782632b38f9a60bf5a1';
    private const HASH_B = 'aa11266b0873bfc80fd1f782632b38f9a60bf5a1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUsers();
    }

    // --- options ------------------------------------------------------------

    public function testOptionsForbiddenForNonAdmin(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser('plain-link'));

        $this->client->request('GET', '/requests/' . $request->getId() . '/link-torrent/options');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOptionsAnswers409WhenClientIsUnconfigured(): void
    {
        // No qbittorrent Integration row seeded → the real client is unconfigured.
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->client->request('GET', '/requests/' . $request->getId() . '/link-torrent/options');

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertFalse($data['ok']);
        self::assertSame('Torrent download client is not configured.', $data['message']);
    }

    public function testOptionsListsTorrentsIncompleteFirstWithLinkedFlags(): void
    {
        $this->stubQbittorrent([
            ['hash' => self::HASH_B, 'name' => 'Already Linked', 'state' => 'stalledUP', 'progress' => 1.0, 'size' => 99],
            ['hash' => self::HASH_A, 'name' => 'Red Rising', 'state' => 'downloading', 'progress' => 0.42, 'size' => 1234],
        ]);

        $request = $this->seedRequest();
        // Another request's in-flight job already tracks HASH_B → flagged linked.
        $other = $this->seedRequest(externalId: 'ext-link-other');
        $job = new DownloadJob('torrent', 'x', ReleaseCandidate::PROTOCOL_TORRENT, $other);
        $job->setStatus(DownloadJob::STATUS_DOWNLOADING)->setClientRef(self::HASH_B);
        $this->em->persist($job);
        $this->em->flush();

        $this->client->loginUser($this->loadUser());
        $this->client->request('GET', '/requests/' . $request->getId() . '/link-torrent/options');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertCount(2, $data['torrents']);
        // Incomplete torrents sort first — they are the likely link targets.
        self::assertSame(self::HASH_A, $data['torrents'][0]['id']);
        self::assertFalse($data['torrents'][0]['linked']);
        // JsonResponse drops the zero fraction, so 42.0 arrives as int 42.
        self::assertSame(42, $data['torrents'][0]['progress']);
        self::assertSame(self::HASH_B, $data['torrents'][1]['id']);
        self::assertTrue($data['torrents'][1]['linked']);
    }

    // --- link ---------------------------------------------------------------

    public function testLinkCreatesDownloadingJobAndAnswersReRenderedRow(): void
    {
        $this->stubQbittorrent([
            ['hash' => self::HASH_A, 'name' => 'Red Rising', 'state' => 'downloading', 'progress' => 0.42, 'size' => 1234],
        ]);
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => $this->csrfToken()]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame('Linked to torrent — will import when the download completes.', $data['message']);
        // The re-rendered row carries the filter hooks so it can swap in place.
        self::assertStringContainsString('data-requests-filter-target="row"', $data['row']);

        $this->em->clear();
        $fresh = $this->em->find(BookRequest::class, $request->getId());
        self::assertSame(BookRequest::STATUS_APPROVED, $fresh->getStatus());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $fresh->getDeliveryStatus());

        $job = self::getContainer()->get('doctrine')->getRepository(DownloadJob::class)
            ->findOneBy(['bookRequest' => $request->getId()]);
        self::assertNotNull($job);
        self::assertSame(self::HASH_A, $job->getClientRef());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame('torrent', $job->getSource());
        self::assertSame('manual-link:' . self::HASH_A, $job->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $job->getProtocol());
        self::assertSame(42, $job->getProgress());
        self::assertSame(1234, $job->getSizeBytes());
        self::assertStringContainsString('Red Rising', (string) $job->getStatusMessage());
    }

    public function testLinkRejectsMalformedHash(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => 'not-a-torrent-hash', '_csrf_token' => $this->csrfToken()]);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->json()['ok']);
    }

    public function testLinkAnswers409WhenClientIsUnconfigured(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => $this->csrfToken()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Torrent download client is not configured.', $this->json()['message']);
    }

    public function testLinkAnswers409ForHashUnknownToTheClient(): void
    {
        $this->stubQbittorrent([
            ['hash' => self::HASH_B, 'name' => 'Some Other Torrent', 'state' => 'downloading', 'progress' => 0.1],
        ]);
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => $this->csrfToken()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Torrent not found in the download client.', $this->json()['message']);
    }

    public function testLinkAnswers409WhenARunningJobAlreadyExists(): void
    {
        $this->stubQbittorrent([
            ['hash' => self::HASH_A, 'name' => 'Red Rising', 'state' => 'downloading', 'progress' => 0.42],
        ]);
        $request = $this->seedRequest(status: BookRequest::STATUS_APPROVED);
        $job = new DownloadJob('torrent', 'x', ReleaseCandidate::PROTOCOL_TORRENT, $request);
        $job->setStatus(DownloadJob::STATUS_DOWNLOADING);
        $this->em->persist($job);
        $this->em->flush();

        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => $this->csrfToken()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('A download is already in progress for this request — cancel it first.', $this->json()['message']);
    }

    public function testLinkForbiddenForNonAdmin(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser('plain-link'));

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testLinkRejectsInvalidCsrf(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postLink($request, ['hash' => self::HASH_A, '_csrf_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Replace the container's qBittorrent client with a real one wired to a mock
     * HTTP transport answering torrents/info with the given rows, and a settings
     * stub that makes it configured. Reboot is disabled so the replacement (set
     * before the first request, while the service is still uninstantiated)
     * survives the whole test.
     *
     * @param list<array<string, mixed>> $rows raw qBittorrent torrents/info rows
     */
    private function stubQbittorrent(array $rows): void
    {
        $this->client->disableReboot();

        $integration = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            // No username → login skipped (no cookie request).
            ->setCredentials([])
            ->setEnabled(true);

        $settings = $this->createStub(TorrentClientSettings::class);
        $settings->method('qbittorrentIntegration')->willReturn($integration);
        $settings->method('getTorrentClientConfig')->willReturn(TorrentClientConfig::default());

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($rows, JSON_THROW_ON_ERROR)));

        self::getContainer()->set(
            QbittorrentDownloadClient::class,
            new QbittorrentDownloadClient($http, $settings, new NullLogger()),
        );
    }

    /** @param array<string, string> $params */
    private function postLink(BookRequest $request, array $params): void
    {
        $this->client->request(
            'POST',
            '/requests/' . $request->getId() . '/link-torrent',
            $params,
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /**
     * The stateful CSRF token is minted into the row's "Link torrent" button;
     * GET the page so the client's session carries the matching token.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/requests');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('.request-btn-link-torrent')->attr('data-link-torrent-token-param');
        self::assertNotEmpty($token);

        return $token;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedRequest(string $status = BookRequest::STATUS_PENDING, string $externalId = 'ext-link-1'): BookRequest
    {
        $book = new Book('grimmory', $externalId, 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $request = new BookRequest($this->loadUser(), $book);
        $request->setStatus($status);

        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedUsers(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $admin = new User('admin-link');
        $admin->setRoles([User::ROLE_ADMIN]);
        $admin->setPassword($hasher->hashPassword($admin, 'x'));
        $this->em->persist($admin);

        $plain = new User('plain-link');
        $plain->setPassword($hasher->hashPassword($plain, 'x'));
        $this->em->persist($plain);

        $this->em->flush();
    }

    private function loadUser(string $username = 'admin-link'): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
