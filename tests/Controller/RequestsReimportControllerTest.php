<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Download\Client\QbittorrentDownloadClient;
use App\Download\Client\TorrentClientSettings;
use App\Download\Torrent\TorrentClientConfig;
use App\Download\Torrent\TorrentFinalizerInterface;
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
 * Covers the permission-gated reimport/re-get endpoints for fulfilled requests:
 * while the torrent's raw files are still in the download client a reimport
 * queues ReimportDownloadJob; once they are gone the endpoint answers a 409
 * carrying the re-get options (automatic re-download / interactive search), and
 * the re-get endpoint sends the request back through the fulfillment pipeline.
 */
final class RequestsReimportControllerTest extends WebTestCase
{
    private const HASH = '3601266b0873bfc80fd1f782632b38f9a60bf5a1';

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
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
        $this->seedUsers();
    }

    // --- reimport -------------------------------------------------------------

    public function testReimportForbiddenWithoutRole(): void
    {
        $request = $this->seedFulfilled();
        $this->client->loginUser($this->loadUser('plain-reimport'));

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReimportRoleSeesReimportButtonOnFulfilledRow(): void
    {
        $this->seedFulfilled();
        $this->client->loginUser($this->loadUser());

        $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.request-btn-reimport');
        self::assertSelectorExists('form[data-request-actions-reget]');
        // Without ROLE_INTERACTIVE_SEARCH there is no search fallback button.
        self::assertSelectorNotExists('.request-btn-search');
    }

    public function testReimportQueuesReimportDownloadJobWhenSourceIsAvailable(): void
    {
        $this->stubQbittorrent();
        $this->stubFinalizer('/downloads/complete/Red Rising');
        $request = $this->seedFulfilled();
        // Non-admin: ROLE_REIMPORT alone is enough.
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame('Reimport queued: the files will be re-imported into the library.', $data['message']);
        // The re-rendered row carries the filter hooks so it can swap in place.
        self::assertStringContainsString('data-requests-filter-target="row"', $data['row']);
        self::assertStringContainsString('ReimportDownloadJob', $this->messengerQueueDump());
    }

    public function testReimportAnswers409WhenRequestHasNoCompletedDownload(): void
    {
        // Fulfilled by status but with no download job at all → nothing to re-import.
        $request = $this->seedFulfilled(status: BookRequest::STATUS_AVAILABLE, withJob: false);
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertFalse($data['ok']);
        self::assertArrayNotHasKey('unavailable', $data);
        self::assertSame('No completed download to re-import for this request.', $data['message']);
        self::assertSame('', $this->messengerQueueDump());
    }

    public function testReimportAnswers409WhenRequestIsNotFulfilled(): void
    {
        $request = $this->seedFulfilled();
        $this->client->loginUser($this->loadUser());
        // Mint the row token while the request is still fulfilled, then regress it.
        $token = $this->reimportToken();
        $fresh = $this->em->find(BookRequest::class, $request->getId());
        $fresh->setStatus(BookRequest::STATUS_PENDING)->setDeliveryStatus(null);
        $this->em->flush();

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(409);
        self::assertFalse($this->json()['ok']);
    }

    public function testReimportAnswersUnavailableWithRegetFlagsForDirectDownload(): void
    {
        // Ebook fulfilled through the direct-download pipeline: no torrent files to
        // reimport. Automatic fulfillment defaults on → canAuto; no search role → !canSearch.
        $request = $this->seedFulfilled(protocol: ReleaseCandidate::PROTOCOL_HTTP);
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertFalse($data['ok']);
        self::assertTrue($data['unavailable']);
        self::assertTrue($data['canAuto']);
        self::assertFalse($data['canSearch']);
        self::assertSame('Original files are no longer available in the download client.', $data['message']);
        self::assertSame('', $this->messengerQueueDump());
    }

    public function testReimportUnavailableOffersSearchToInteractiveSearcher(): void
    {
        $request = $this->seedFulfilled(protocol: ReleaseCandidate::PROTOCOL_HTTP);
        $this->client->loginUser($this->loadUser('searcher-reimport'));

        // The fulfilled row renders the interactive-search fallback button for them.
        $this->client->request('GET', '/requests');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.request-btn-search');

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertTrue($data['unavailable']);
        self::assertTrue($data['canSearch']);
    }

    public function testReimportUnavailableCanAutoFalseForAudiobookWithoutTorrentStack(): void
    {
        // Torrent job but the client is unconfigured → source unavailable; and an
        // audiobook re-get needs the Prowlarr + qBittorrent stack → canAuto false.
        $request = $this->seedFulfilled(audiobook: true);
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertTrue($data['unavailable']);
        self::assertFalse($data['canAuto']);
    }

    public function testReimportUnavailableWhenTorrentFilesAreGoneFromTheClient(): void
    {
        $this->stubQbittorrent();
        $this->stubFinalizer(null); // torrent gone (or its files deleted)
        $request = $this->seedFulfilled();
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reimport', ['_csrf_token' => $this->reimportToken()]);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->json()['unavailable']);
        self::assertSame('', $this->messengerQueueDump());
    }

    // --- reget ----------------------------------------------------------------

    public function testRegetRestartsFulfillmentWithApprovedStatusAndClearedDelivery(): void
    {
        $request = $this->seedFulfilled(protocol: ReleaseCandidate::PROTOCOL_HTTP, status: BookRequest::STATUS_AVAILABLE);
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reget', ['_csrf_token' => $this->regetToken()]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame('Re-download started.', $data['message']);
        self::assertStringContainsString('data-requests-filter-target="row"', $data['row']);

        // The dispatch handlers only act on APPROVED requests, so the AVAILABLE
        // request re-enters the pipeline as approved with its delivery mirror cleared.
        $this->em->clear();
        $fresh = $this->em->find(BookRequest::class, $request->getId());
        self::assertSame(BookRequest::STATUS_APPROVED, $fresh->getStatus());
        self::assertNull($fresh->getDeliveryStatus());
        self::assertStringContainsString('DispatchReleaseSearch', $this->messengerQueueDump());
    }

    public function testRegetDispatchesTorrentSearchForAudiobooks(): void
    {
        $request = $this->seedFulfilled(audiobook: true, status: BookRequest::STATUS_AVAILABLE);
        $this->client->loginUser($this->loadUser());

        $this->post('/requests/' . $request->getId() . '/reget', ['_csrf_token' => $this->regetToken()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DispatchTorrentSearch', $this->messengerQueueDump());
    }

    public function testRegetAnswers409WhenRequestIsNotFulfilled(): void
    {
        $request = $this->seedFulfilled();
        $this->client->loginUser($this->loadUser());
        $token = $this->regetToken();
        $fresh = $this->em->find(BookRequest::class, $request->getId());
        $fresh->setStatus(BookRequest::STATUS_PENDING)->setDeliveryStatus(null);
        $this->em->flush();

        $this->post('/requests/' . $request->getId() . '/reget', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(409);
        $data = $this->json();
        self::assertFalse($data['ok']);
        self::assertSame('Only fulfilled requests can be re-downloaded.', $data['message']);
        self::assertSame('', $this->messengerQueueDump());
    }

    public function testRegetForbiddenWithoutRole(): void
    {
        $request = $this->seedFulfilled();
        $this->client->loginUser($this->loadUser('plain-reimport'));

        $this->post('/requests/' . $request->getId() . '/reget', ['_csrf_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Replace the container's qBittorrent client with a real one wired to a mock
     * HTTP transport and a settings stub that makes it configured (same seam as
     * RequestsLinkTorrentControllerTest). Reboot is disabled so the replacement
     * survives the whole test.
     */
    private function stubQbittorrent(): void
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

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('[]'));

        self::getContainer()->set(
            QbittorrentDownloadClient::class,
            new QbittorrentDownloadClient($http, $settings, new NullLogger()),
        );
    }

    /** Stub the finalizer's source probe: $path when the files are still there, null when gone. */
    private function stubFinalizer(?string $path): void
    {
        $this->client->disableReboot();

        $finalizer = $this->createStub(TorrentFinalizerInterface::class);
        $finalizer->method('sourceAvailability')->willReturn($path);

        self::getContainer()->set(TorrentFinalizerInterface::class, $finalizer);
    }

    /**
     * The stateful CSRF token is minted into the row's reimport form; GET the
     * page so the client's session carries the matching token.
     */
    private function reimportToken(): string
    {
        return $this->rowToken('form[action$="/reimport"] input[name="_csrf_token"]');
    }

    private function regetToken(): string
    {
        return $this->rowToken('form[action$="/reget"] input[name="_csrf_token"]');
    }

    private function rowToken(string $selector): string
    {
        $crawler = $this->client->request('GET', '/requests');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter($selector)->attr('value');
        self::assertNotEmpty($token);

        return $token;
    }

    /** @param array<string, string> $params */
    private function post(string $url, array $params): void
    {
        $this->client->request('POST', $url, $params, [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function messengerQueueDump(): string
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT body FROM messenger_messages');

        return implode("\n", $rows);
    }

    /**
     * A fulfilled request: either AVAILABLE, or APPROVED with a COMPLETE download
     * job mirrored onto deliveryStatus — both shapes the row treats as fulfilled.
     */
    private function seedFulfilled(
        string $protocol = ReleaseCandidate::PROTOCOL_TORRENT,
        bool $audiobook = false,
        string $status = BookRequest::STATUS_APPROVED,
        bool $withJob = true,
    ): BookRequest {
        $book = new Book('grimmory', 'ext-reimport-' . bin2hex(random_bytes(4)), 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $request = new BookRequest($this->loadUser(), $book);
        $request->setStatus($status);
        $request->setAudiobook($audiobook);
        $this->em->persist($book);
        $this->em->persist($request);

        if ($withJob) {
            $request->setDeliveryStatus(DownloadJob::STATUS_COMPLETE);
            $job = new DownloadJob($protocol === ReleaseCandidate::PROTOCOL_TORRENT ? 'torrent' : 'libgen', 'x', $protocol, $request);
            $job->setStatus(DownloadJob::STATUS_COMPLETE);
            if ($protocol === ReleaseCandidate::PROTOCOL_TORRENT) {
                $job->setClientRef(self::HASH);
            }
            $this->em->persist($job);
        }

        $this->em->flush();

        return $request;
    }

    private function seedUsers(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $reimporter = new User('reimporter');
        $reimporter->setRoles([User::ROLE_REIMPORT]);
        $reimporter->setPassword($hasher->hashPassword($reimporter, 'x'));
        $this->em->persist($reimporter);

        $searcher = new User('searcher-reimport');
        $searcher->setRoles([User::ROLE_REIMPORT, User::ROLE_INTERACTIVE_SEARCH]);
        $searcher->setPassword($hasher->hashPassword($searcher, 'x'));
        $this->em->persist($searcher);

        $plain = new User('plain-reimport');
        $plain->setPassword($hasher->hashPassword($plain, 'x'));
        $this->em->persist($plain);

        $this->em->flush();
    }

    private function loadUser(string $username = 'reimporter'): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
