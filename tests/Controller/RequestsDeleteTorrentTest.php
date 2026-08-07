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
 * Torrent-aware request deletion: when the request's latest job is a torrent
 * still present in the download client (downloading or seeding) and the
 * Settings → Torrents ask-what-to-do toggle is on, the delete endpoint
 * withholds the deletion until the client sends a torrent_action — keep (re-tag
 * and leave seeding) or remove (delete the torrent and its files). With the
 * toggle off, the configured default action (keep|remove) is applied silently
 * through the same code paths, never asking. No active torrent → plain delete.
 * Client failures never block the deletion; they only change the toast message.
 */
final class RequestsDeleteTorrentTest extends WebTestCase
{
    private const HASH = '3601266b0873bfc80fd1f782632b38f9a60bf5a1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $qbCalls = [];

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
        $this->qbCalls = [];
    }

    public function testPlainDeleteWithoutAnyTorrentJobStillWorks(): void
    {
        $request = $this->seedRequest();
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed.', $data['message']);
        self::assertArrayNotHasKey('needsTorrentDecision', $data);
        $this->assertRequestGone($request);
    }

    public function testPromptOffDefaultKeepWithEmptyTagDeletesWithoutTouchingTheClient(): void
    {
        // Prompt off + default keep + empty released tag: keep-with-no-tag means
        // there is nothing to tell the client at all — silent plain-looking delete.
        // deleteDefaultAction is left absent to also cover its keep default.
        $this->seedQbIntegrationRow(['deletePromptEnabled' => false, 'releasedTag' => '']);
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed.', $data['message']);
        self::assertArrayNotHasKey('needsTorrentDecision', $data);
        $this->assertRequestGone($request);
        self::assertSame([], $this->qbCalls, 'Keep with an empty tag must not touch the client at all.');
    }

    public function testPromptOffDefaultKeepWithTagTagsTheTorrentSilently(): void
    {
        // Prompt off + default keep + a tag (the stored default): same behavior as
        // the prompt's Keep choice, applied without asking.
        $this->seedQbIntegrationRow(['deletePromptEnabled' => false, 'deleteDefaultAction' => 'keep']);
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_COMPLETE);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed. Torrent kept seeding and tagged.', $data['message']);
        self::assertArrayNotHasKey('needsTorrentDecision', $data);
        $this->assertRequestGone($request);

        $addTags = array_values(array_filter($this->qbCalls, static fn (array $c): bool => str_ends_with($c['url'], '/api/v2/torrents/addTags')));
        self::assertCount(1, $addTags);
        self::assertStringContainsString('hashes=' . self::HASH, $addTags[0]['body']);
        self::assertStringContainsString('tags=' . rawurlencode('spinescout-unmonitored'), $addTags[0]['body']);
    }

    public function testPromptOffDefaultRemoveDeletesTheTorrentWithFilesSilently(): void
    {
        // Prompt off + default remove: same behavior as the prompt's Remove choice,
        // applied without asking.
        $this->seedQbIntegrationRow(['deletePromptEnabled' => false, 'deleteDefaultAction' => 'remove']);
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed. Torrent deleted from the download client.', $data['message']);
        self::assertArrayNotHasKey('needsTorrentDecision', $data);
        $this->assertRequestGone($request);

        self::assertCount(1, $this->qbCalls);
        self::assertStringEndsWith('/api/v2/torrents/delete', $this->qbCalls[0]['url']);
        self::assertStringContainsString('hashes=' . self::HASH, $this->qbCalls[0]['body']);
        self::assertStringContainsString('deleteFiles=true', $this->qbCalls[0]['body']);
    }

    public function testActiveTorrentWithoutADecisionAsksAndDoesNotDelete(): void
    {
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['needsTorrentDecision']);
        self::assertSame('downloading', $data['torrentState']);
        self::assertArrayNotHasKey('removed', $data);

        $this->em->clear();
        self::assertNotNull($this->em->find(BookRequest::class, $request->getId()), 'No decision → no deletion.');
        self::assertSame([], $this->qbCalls);
    }

    public function testCompletedTorrentReportsSeedingState(): void
    {
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_COMPLETE);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['needsTorrentDecision']);
        self::assertSame('seeding', $data['torrentState']);
    }

    public function testKeepTagsTheTorrentAndDeletesTheRequest(): void
    {
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_COMPLETE);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request), 'keep');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed. Torrent kept seeding and tagged.', $data['message']);
        $this->assertRequestGone($request);

        // createTags (older clients don't auto-create) then addTags, both carrying
        // the default released tag; addTags targets the job's hash.
        $urls = array_column($this->qbCalls, 'url');
        self::assertCount(1, array_filter($urls, static fn (string $u): bool => str_ends_with($u, '/api/v2/torrents/createTags')));
        $addTags = array_values(array_filter($this->qbCalls, static fn (array $c): bool => str_ends_with($c['url'], '/api/v2/torrents/addTags')));
        self::assertCount(1, $addTags);
        self::assertStringContainsString('hashes=' . self::HASH, $addTags[0]['body']);
        self::assertStringContainsString('tags=' . rawurlencode('spinescout-unmonitored'), $addTags[0]['body']);
    }

    public function testRemoveDeletesTheTorrentWithFilesAndTheRequest(): void
    {
        $this->stubQbittorrent();
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request), 'remove');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertSame('Request removed. Torrent deleted from the download client.', $data['message']);
        $this->assertRequestGone($request);

        self::assertCount(1, $this->qbCalls);
        self::assertStringEndsWith('/api/v2/torrents/delete', $this->qbCalls[0]['url']);
        self::assertStringContainsString('hashes=' . self::HASH, $this->qbCalls[0]['body']);
        self::assertStringContainsString('deleteFiles=true', $this->qbCalls[0]['body']);
    }

    public function testClientFailureStillDeletesTheRequestButWarnsInTheMessage(): void
    {
        // Every client call fails at transport level (nothing listening) — the
        // request must be deleted anyway, with the degraded message.
        $this->stubQbittorrent(failTransport: true);
        $request = $this->seedRequest();
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser());

        $this->postDelete($request, $this->csrfToken($request), 'remove');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertTrue($data['removed']);
        self::assertStringStartsWith('Request removed. (Could not update the torrent:', $data['message']);
        $this->assertRequestGone($request);
    }

    public function testOwnerDeletingWithoutTorrentActionAlsoGetsThePrompt(): void
    {
        // The popup flow is not admin-only: a plain owner deleting their own
        // request goes through the same decision gate.
        $this->stubQbittorrent();
        $request = $this->seedRequest(owner: 'plain-del');
        $this->seedTorrentJob($request, DownloadJob::STATUS_DOWNLOADING);
        $this->client->loginUser($this->loadUser('plain-del'));

        $this->postDelete($request, $this->csrfToken($request));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['needsTorrentDecision']);
        $this->em->clear();
        self::assertNotNull($this->em->find(BookRequest::class, $request->getId()));
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Replace the container's qBittorrent client with a real one wired to a mock
     * HTTP transport (recording every call) and a settings stub that makes it
     * configured. Reboot is disabled so the replacement survives the whole test.
     * With $failTransport every call throws a transport error instead.
     */
    private function stubQbittorrent(bool $failTransport = false): void
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

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($failTransport): MockResponse {
            $this->qbCalls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];
            if ($failTransport) {
                return new MockResponse('', ['error' => 'Connection refused']);
            }

            return new MockResponse('');
        });

        self::getContainer()->set(
            QbittorrentDownloadClient::class,
            new QbittorrentDownloadClient($http, $settings, new NullLogger()),
        );
    }

    /**
     * The controller reads the delete-prompt toggle from the stored qbittorrent
     * Integration row (absent row/keys = defaults, i.e. prompt on).
     *
     * @param array<string, mixed> $configOverrides
     */
    private function seedQbIntegrationRow(array $configOverrides): void
    {
        $row = (new Integration(Integration::KIND_QBITTORRENT))
            ->setBaseUrl('http://qb.test')
            ->setCredentials([])
            ->setEnabled(true)
            ->setOptions(['config' => TorrentClientConfig::fromArray($configOverrides)->toArray()]);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function postDelete(BookRequest $request, string $token, ?string $torrentAction = null): void
    {
        $params = ['_csrf_token' => $token];
        if ($torrentAction !== null) {
            $params['torrent_action'] = $torrentAction;
        }

        $this->client->request(
            'POST',
            '/requests/' . $request->getId() . '/delete',
            $params,
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /**
     * The stateful CSRF token is minted into the row's delete form; GET the page
     * so the client's session carries the matching token, then scrape it.
     */
    private function csrfToken(BookRequest $request): string
    {
        $crawler = $this->client->request('GET', '/requests');
        self::assertResponseIsSuccessful();
        $token = $crawler
            ->filter('form[action="/requests/' . $request->getId() . '/delete"] input[name="_csrf_token"]')
            ->attr('value');
        self::assertNotEmpty($token);
        // The page render is part of arranging, not the behavior under test.
        $this->qbCalls = [];

        return $token;
    }

    private function assertRequestGone(BookRequest $request): void
    {
        $this->em->clear();
        self::assertNull($this->em->find(BookRequest::class, $request->getId()));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedRequest(string $owner = 'admin-del'): BookRequest
    {
        $book = new Book('grimmory', 'ext-del-' . bin2hex(random_bytes(4)), 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $request = new BookRequest($this->loadUser($owner), $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);

        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedTorrentJob(BookRequest $request, string $status): DownloadJob
    {
        $job = new DownloadJob('torrent', 'x', ReleaseCandidate::PROTOCOL_TORRENT, $request);
        $job->setStatus($status)->setClientRef(self::HASH);
        $request->setDeliveryStatus($status);
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function seedUsers(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $admin = new User('admin-del');
        $admin->setRoles([User::ROLE_ADMIN]);
        $admin->setPassword($hasher->hashPassword($admin, 'x'));
        $this->em->persist($admin);

        $plain = new User('plain-del');
        $plain->setPassword($hasher->hashPassword($plain, 'x'));
        $this->em->persist($plain);

        $this->em->flush();
    }

    private function loadUser(string $username = 'admin-del'): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
