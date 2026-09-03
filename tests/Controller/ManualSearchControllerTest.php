<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Download\Client\DownloadClientInterface;
use App\Download\FulfillmentLog;
use App\Download\Mam\MamFulfillment;
use App\Download\Torrent\TorrentFulfillment;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Mirror\MirrorListNormalizer;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\TorrentMatchScorer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Manual Search is interactive search with no book: the search half is shared
 * (InteractiveSearchRunner, covered by InteractiveSearchControllerTest), so what
 * is exercised here is what is genuinely different — its own permission, the
 * per-row suggestedName, the operator-approved filename and its sanitisation,
 * and jobs that carry the manual name/medium instead of a BookRequest.
 */
final class ManualSearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;

    /** Requested MAM URLs + form bodies ("url|body"), for asserting on search calls. */
    private array $mamRequests = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->integrations = $container->get(IntegrationRepository::class);

        $this->em->createQuery('DELETE FROM '.DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM '.BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.Integration::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
        $this->seedUsers();
        $this->seedConfig();
    }

    public function testRequiresLogin(): void
    {
        $this->client->request('GET', '/manual-search');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Manual search is its own permission, deliberately separate from interactive
     * search: it downloads an arbitrary file under an arbitrary name, so holding
     * ROLE_INTERACTIVE_SEARCH is not enough.
     */
    public function testPageForbiddenWithoutManualSearchRole(): void
    {
        $this->client->loginUser($this->loadUser('interactive'));

        $this->client->request('GET', '/manual-search');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPageRendersForAUserWithTheRole(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->client->request('GET', '/manual-search');

        self::assertResponseIsSuccessful();
    }

    public function testSourcesForbiddenWithoutManualSearchRole(): void
    {
        $this->client->loginUser($this->loadUser('interactive'));

        $this->postJson('/manual-search/sources', ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->postJson('/manual-search/sources', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Invalid CSRF token.', $this->json()['error']);
    }

    public function testGrabRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->postJson('/manual-search/grab', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
    }

    /** The same sources, in the same operator-priority order, as interactive search. */
    public function testSourcesAreListedInOperatorPriorityOrder(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/manual-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $sources = $this->json()['sources'];
        self::assertSame(['annas_archive', 'libgen', 'torrent'], array_column($sources, 'id'));
        self::assertSame(['https://aa.test'], $sources[0]['mirrors']);
    }

    public function testRunRejectsUnknownSource(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/manual-search/run', ['_token' => $token, 'source' => 'not_a_source', 'title' => 'Red Rising']);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Every row carries the name the panel pre-fills into its editable field. Here
     * the release name parses cleanly ("Author - Title"), so it renders through the
     * operator's ebook template with the row's own format as the extension.
     */
    public function testRunAttachesASuggestedNameToEveryRow(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam([$this->mamSearchRow(id: 111, dl: 'dl-a', title: 'Pierce Brown - Red Rising')]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'    => $token,
            'source'    => 'mam',
            'title'     => 'Red Rising',
            'audiobook' => false,
        ]);

        self::assertResponseIsSuccessful();
        $results = $this->json()['results'];
        self::assertNotSame([], $results);
        self::assertSame('Pierce Brown - Red Rising.epub', $results[0]['suggestedName']);
    }

    /**
     * An audiobook run suggests an album FOLDER name — the torrent client's
     * template, and no file extension.
     */
    public function testRunSuggestsAFolderNameForAudiobooks(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam([$this->mamSearchRow(id: 111, dl: 'dl-a', title: 'Andy Weir - Project Hail Mary [M4B]')]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'    => $token,
            'source'    => 'mam',
            'title'     => 'Project Hail Mary',
            'audiobook' => true,
        ]);

        self::assertResponseIsSuccessful();
        $results = $this->json()['results'];
        self::assertNotSame([], $results);
        self::assertSame('Andy Weir - Project Hail Mary', $results[0]['suggestedName']);
    }

    // --- pasting a MyAnonamouse torrent link --------------------------------

    /**
     * The feature: a pasted /t/{id} link resolves to exactly ONE row, carrying the
     * same keys a searched row does — the `dl` hash the grab needs and the
     * suggestedName the panel pre-fills — so nothing downstream can tell the
     * difference. `searchUrl` points at the torrent's own page, not a search for it.
     */
    public function testRunResolvesAPastedTorrentLinkToASingleRow(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam(
            [$this->mamSearchRow(id: 182137, dl: 'dl-182137', title: 'Pierce Brown - Red Rising')],
            [182137 => $this->mamDetailPage('dl-182137')],
        );

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => 'https://www.myanonamouse.net/t/182137',
            'audiobook'  => false,
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('mam', $body['source']);
        self::assertSame('https://mam.test/t/182137', $body['searchUrl']);
        self::assertSame(182137, $body['resolvedTorrentId']);
        self::assertCount(1, $body['results']);

        $row = $body['results'][0];
        self::assertSame('dl-182137', $row['mam']['dlHash']);
        self::assertSame(182137, $row['mam']['torrentId']);
        self::assertSame('Pierce Brown - Red Rising.epub', $row['suggestedName']);
        // Not scored against anything: the operator named this exact torrent, so it
        // must never be hidden for ranking below the match threshold.
        self::assertSame(100, $row['matchPct']);
        self::assertTrue($row['qualifies']);
        self::assertNotSame([], $row['links']);
    }

    /** A bare path and a bare id are the same paste, minus the ceremony. */
    public function testRunResolvesABareTorrentIdToo(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam(
            [$this->mamSearchRow(id: 182137, dl: 'dl-182137')],
            [182137 => $this->mamDetailPage('dl-182137')],
        );

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => '/t/182137',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['results']);
    }

    /**
     * A MAM link pasted while another source is selected must say so rather than
     * quietly switching the operator's source out from under them.
     */
    public function testRunRefusesATorrentLinkWhenAnotherSourceIsSelected(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'annas_archive',
            'torrentUrl' => 'https://www.myanonamouse.net/t/182137',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('pick MyAnonamouse', $this->json()['error']);
        self::assertSame([], $this->mamRequests, 'a refused link must not hit MAM at all');
    }

    /** An unknown/deleted id is a clean inline message, never a 500. */
    public function testRunReportsAnUnknownTorrentIdInline(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam([], []);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => 'https://www.myanonamouse.net/t/999999',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('no torrent 999999', $this->json()['error']);
    }

    /**
     * A dead cookie makes MAM serve its login page with a 200; the operator has to
     * be told to fix the session, not that the torrent is missing.
     */
    public function testRunReportsADeadSessionInline(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam([], [
            182137 => '<html><body><form action="/takelogin.php"><input type="password" name="password"></form></body></html>',
        ]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => 'https://www.myanonamouse.net/t/182137',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('session cookie', $this->json()['error']);
    }

    /** Junk in the link field is refused before anything is requested. */
    public function testRunRejectsSomethingThatIsNotATorrentLink(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => 'https://example.com/t/182137',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->mamRequests);
    }

    /**
     * The contract that makes the feature worth having: a resolved row grabs
     * through the untouched MamFulfillment path, producing exactly the book-less
     * DownloadJob a searched MAM row produces.
     */
    public function testGrabOfAResolvedRowCreatesTheSameBooklessJob(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam(
            [$this->mamSearchRow(id: 182137, dl: 'dl-182137', title: 'Pierce Brown - Red Rising')],
            [182137 => $this->mamDetailPage('dl-182137')],
        );

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/run', [
            '_token'     => $token,
            'source'     => 'mam',
            'torrentUrl' => 'https://www.myanonamouse.net/t/182137',
        ]);
        self::assertResponseIsSuccessful();
        $row = $this->json()['results'][0];

        $this->postJson('/manual-search/grab', [
            '_token'    => $token,
            'source'    => 'mam',
            'title'     => $row['title'],
            'saveAs'    => $row['suggestedName'],
            'sizeBytes' => $row['sizeBytes'],
            'audiobook' => false,
            'mam'       => $row['mam'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ok']);

        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertSame('mam', $jobs[0]->getSource());
        self::assertSame('182137', $jobs[0]->getSourceId());
        self::assertNull($jobs[0]->getBookRequest());
        self::assertSame('Pierce Brown - Red Rising.epub', $jobs[0]->getManualName());
    }

    /**
     * The whole point of the feature: a grab with no book behind it. The job holds
     * the operator's approved name and their medium choice, which is all
     * TorrentFinalizer needs to deliver the file.
     */
    public function testGrabCreatesABookLessJobCarryingTheManualFields(): void
    {
        $this->stubTorrents();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/grab', $this->grabPayload($token, saveAs: 'Pierce Brown - Red Rising', audiobook: true));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);

        $this->em->clear();
        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertNull($jobs[0]->getBookRequest());
        self::assertSame('torrent', $jobs[0]->getSource());
        self::assertSame('guid-1', $jobs[0]->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $jobs[0]->getProtocol());
        self::assertSame('Pierce Brown - Red Rising', $jobs[0]->getManualName());
        self::assertTrue($jobs[0]->isManualAudiobook());
        self::assertSame(DownloadJob::STATUS_QUEUED, $jobs[0]->getStatus());
    }

    /**
     * saveAs is operator input that becomes a path on disk, so it is sanitized
     * server-side: no separators, no traversal, nothing that could land the file
     * outside the destination directory.
     */
    public function testGrabStripsPathTraversalFromSaveAs(): void
    {
        $this->stubTorrents();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/grab', $this->grabPayload($token, saveAs: '../../etc/passwd'));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertSame('passwd', $jobs[0]->getManualName());
    }

    /** Cleared the field? Fall back to the same suggestion the panel showed. */
    public function testGrabFallsBackToTheSuggestedNameWhenSaveAsIsBlank(): void
    {
        $this->stubTorrents();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/grab', $this->grabPayload(
            $token,
            saveAs: '   ',
            title: 'Pierce.Brown-Red.Rising.2014.Retail.EPUB',
        ));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        // No extension at grab time: a torrent's real extension is only known once
        // its files are on disk, and TorrentFinalizer appends it there.
        self::assertSame('Pierce Brown - Red Rising (2014)', $jobs[0]->getManualName());
    }

    public function testGrabRejectsAResultWithoutALink(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $payload = $this->grabPayload($token);
        $payload['link'] = '';
        $this->postJson('/manual-search/grab', $payload);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This result has no download link.', $this->json()['error']);
    }

    public function testGrabWithoutATorrentClientReturns409(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/manual-search/grab', $this->grabPayload($token));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Torrent downloading is not configured.', $this->json()['error']);
    }

    public function testMamGrabRejectsAMissingMamBlock(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/grab', [
            '_token' => $token,
            'source' => 'mam',
            'title'  => 'Red Rising',
            'saveAs' => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This result is missing its MyAnonamouse download data.', $this->json()['error']);
    }

    /**
     * The MAM wedge choice is re-validated exactly as in interactive search — a
     * release that is already free for this account never spends one, whatever the
     * panel posted.
     */
    public function testMamGrabNeverSpendsAWedgeOnAnAlreadyFreeRelease(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam(isVip: true);
        $this->stubMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/manual-search/grab', [
            '_token'   => $token,
            'source'   => 'mam',
            'title'    => 'Red Rising',
            'saveAs'   => 'Pierce Brown - Red Rising',
            'useWedge' => true,
            'mam'      => [
                'torrentId'         => 123456,
                'dlHash'            => 'dl-hash-abc',
                'free'              => false,
                'flVip'             => true,
                'personalFreeleech' => false,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);
        self::assertStringContainsString('/tor/download.php/dl-hash-abc|', implode("\n", $this->mamRequests));

        $this->em->clear();
        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertNull($jobs[0]->getBookRequest());
        self::assertSame('mam', $jobs[0]->getSource());
        self::assertSame('Pierce Brown - Red Rising', $jobs[0]->getManualName());
    }

    public function testDownloadRejectsAResultWithoutLinks(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/manual-search/download', [
            '_token' => $token,
            'source' => 'annas_archive',
            'links'  => [],
            'title'  => 'Red Rising',
            'saveAs' => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This result has no download links.', $this->json()['error']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * The grab payload the panel posts for a picked torrent row.
     *
     * @return array<string, mixed>
     */
    private function grabPayload(
        string $token,
        string $saveAs = 'Red Rising',
        bool $audiobook = false,
        string $title = 'Pierce Brown - Red Rising',
    ): array {
        return [
            '_token'    => $token,
            'source'    => 'torrent',
            'id'        => 'guid-1',
            'link'      => 'magnet:?xt=urn:btih:abc',
            'title'     => $title,
            'format'    => 'epub',
            'sizeBytes' => 1024,
            'seeders'   => 12,
            'indexer'   => 'Some Indexer',
            'audiobook' => $audiobook,
            'saveAs'    => $saveAs,
        ];
    }

    private function stubTorrents(): void
    {
        // Reboot would rebuild the container and drop the stub before the request
        // that needs it (the same dance InteractiveSearchControllerTest does).
        $this->client->disableReboot();
        $torrents = $this->createStub(TorrentFulfillmentInterface::class);
        $torrents->method('isAvailable')->willReturn(true);
        $torrents->method('grab')->willReturn(true);
        self::getContainer()->set(TorrentFulfillment::class, $torrents);
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/manual-search');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-manual-search-token-value]')->attr('data-manual-search-token-value');
        self::assertNotEmpty($token);

        return $token;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $path, array $payload): void
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Two users: 'member' holds ROLE_MANUAL_SEARCH, and 'interactive' holds only
     * ROLE_INTERACTIVE_SEARCH — the 403 case that proves the two permissions are
     * genuinely separate.
     */
    private function seedUsers(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User('member');
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $user->setRoles([User::ROLE_MANUAL_SEARCH]);
        $this->em->persist($user);

        $interactive = new User('interactive');
        $interactive->setPassword($hasher->hashPassword($interactive, 'doesnt-matter'));
        $interactive->setRoles([User::ROLE_INTERACTIVE_SEARCH]);
        $this->em->persist($interactive);

        $this->em->flush();
    }

    private function loadUser(string $username = 'member'): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }

    private function seedConfig(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
            ['id' => 'zlibrary', 'enabled' => false],
            ['id' => 'welib', 'enabled' => false],
            ['id' => 'torrent', 'enabled' => true],
        ]);
    }

    private function saveConfigWithMam(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
            ['id' => 'zlibrary', 'enabled' => false],
            ['id' => 'welib', 'enabled' => false],
            ['id' => 'torrent', 'enabled' => true],
            ['id' => 'mam', 'enabled' => true],
        ]);
    }

    /**
     * @param list<array{id: string, enabled: bool}> $priority
     */
    private function saveConfig(array $priority): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => $priority,
            'mirrors'         => [
                'annas_archive' => ['https://aa.test'],
                'libgen'        => ['https://lg.test'],
            ],
        ], new MirrorListNormalizer());
        $this->integrations->saveDirectDownloadConfig($config, true, $this->em);
        $this->em->flush();
    }

    /** Enabled MAM integration: config + session cookie + account snapshot. */
    private function seedMam(bool $alwaysUseWedge = false, bool $isVip = false): void
    {
        $config = new MyAnonamouseConfig(
            enabled: true,
            baseUrl: 'https://mam.test',
            alwaysUseWedge: $alwaysUseWedge,
        );
        $this->integrations->saveMyAnonamouseConfig($config, true, $this->em);
        $this->em->flush();
        $this->integrations->persistRotatedMamSessionCookie('test-cookie');
        $this->integrations->saveMamAccountState(['isVip' => $isVip]);
    }

    /**
     * Swap the MAM stack for one that answers from canned rows over a mocked HTTP
     * layer — the same pattern InteractiveSearchControllerTest uses (MamFulfillment
     * is final, so a REAL instance is wired over a mock transport).
     *
     * @param list<array<string, mixed>> $searchRows
     */
    private function stubMam(array $searchRows = [], array $detailPages = []): void
    {
        $this->client->disableReboot();
        $this->mamRequests = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($searchRows, $detailPages): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $this->mamRequests[] = $url . '|' . $body;
            if (str_contains($url, 'loadSearchJSONbasic.php')) {
                return new MockResponse(json_encode(['data' => $searchRows, 'found' => \count($searchRows)], JSON_THROW_ON_ERROR));
            }
            // The torrent detail page behind a pasted /t/{id} link. Anything the
            // test did not stub is MAM's own 404, exactly like a deleted torrent.
            if (preg_match('~/t/(\\d+)$~', $url, $m) === 1) {
                $page = $detailPages[(int) $m[1]] ?? null;

                return $page === null
                    ? new MockResponse('Not found', ['http_code' => 404])
                    : new MockResponse($page, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
            }
            if (str_contains($url, '/tor/download.php/')) {
                return new MockResponse(
                    'd8:announce26:https://mam.test/announce4:infod4:name3:fooee',
                    ['response_headers' => ['content-type' => 'application/x-bittorrent']],
                );
            }

            self::fail('unexpected MAM request: ' . $url);
        });
        $mamClient = new MyAnonamouseClient($http, $this->integrations, new NullLogger(), 0);

        $download = $this->createStub(DownloadClientInterface::class);
        $download->method('getProtocol')->willReturn(ReleaseCandidate::PROTOCOL_TORRENT);
        $download->method('isConfigured')->willReturn(true);
        $download->method('addDownload')->willReturn('added-hash');

        $blocked = $this->createStub(BlockedReleaseRepository::class);
        $blocked->method('blockedKeysForBook')->willReturn([]);

        $container = self::getContainer();
        $container->set(MyAnonamouseClient::class, $mamClient);
        $container->set(MamFulfillment::class, new MamFulfillment(
            [$download],
            $mamClient,
            $this->integrations,
            new TorrentMatchScorer(new MatchScorer()),
            $this->integrations,
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
            $blocked,
            new NullLogger(),
        ));
    }

    /**
     * A stand-in for MAM's /t/{id} page. The live markup could not be checked (the
     * operator's session is expired), so this pins only what the parser relies on:
     * the `/tor/download.php/` anchor and a heading.
     */
    private function mamDetailPage(string $dl, string $title = 'Pierce Brown - Red Rising'): string
    {
        return '<html><head><title>' . $title . ' | MyAnonamouse</title></head><body>'
            . '<div id="torDetMainCon"><h1>' . $title . '</h1></div>'
            . '<table><tr><td>Category:</td><td>Ebooks - Sci-Fi</td></tr>'
            . '<tr><td>File Type:</td><td>epub</td></tr><tr><td>Size:</td><td>12.0 MiB</td></tr>'
            . '<tr><td>Seeders:</td><td>7</td></tr></table>'
            . '<a href="/tor/download.php/' . $dl . '">Download</a></body></html>';
    }

    /**
     * One MAM search-endpoint row in the raw JSON shape mapRelease() consumes.
     *
     * @return array<string, mixed>
     */
    private function mamSearchRow(int $id, string $dl, string $title = 'Red Rising'): array
    {
        return [
            'id'                 => $id,
            'title'              => $title,
            'author_info'        => json_encode(['1' => 'Pierce Brown'], JSON_THROW_ON_ERROR),
            'main_cat'           => 14,
            'catname'            => 'Ebooks - Fiction',
            'filetypes'          => 'epub',
            'size'               => '100 MiB',
            'seeders'            => 10,
            'leechers'           => 1,
            'times_completed'    => 5,
            'vip'                => 0,
            'fl_vip'             => 0,
            'free'               => 0,
            'personal_freeleech' => 0,
            'dl'                 => $dl,
            'added'              => '2024-05-01 12:34:56',
        ];
    }
}
