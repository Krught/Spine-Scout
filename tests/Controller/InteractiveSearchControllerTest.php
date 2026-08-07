<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Integration;
use App\Entity\User;
use App\Controller\InteractiveSearchController;
use App\Mirror\MirrorListNormalizer;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\ScoredRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InteractiveSearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->integrations = $container->get(IntegrationRepository::class);

        $this->em->createQuery('DELETE FROM '.Integration::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
        $this->seedUser();
        $this->seedConfig();
    }

    public function testRequiresLogin(): void
    {
        $this->client->request('POST', '/interactive-search/sources');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Interactive search is a per-user permission, not a perk of being logged in: a
     * plain ROLE_USER is refused by the class-level gate before any controller code
     * runs — so it is a 403 even without a valid CSRF token (which such a user cannot
     * obtain anyway, the panel that renders it never being sent to them).
     */
    public function testSourcesForbiddenWithoutInteractiveSearchRole(): void
    {
        $this->client->loginUser($this->loadUser('plain'));

        $this->postJson('/interactive-search/sources', ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGrabForbiddenWithoutInteractiveSearchRole(): void
    {
        $this->client->loginUser($this->loadUser('plain'));

        $this->postJson('/interactive-search/grab', [
            '_token' => 'irrelevant',
            'bookId' => $this->seedBook()->getId(),
            'id'     => 'guid-1',
            'title'  => 'Red Rising',
            'link'   => 'magnet:?xt=urn:btih:abc',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Only the sources the operator left switched on come back, and `enabled`
     * reports whether each is usable right now (mirrors pasted in) rather than
     * whether it is switched on — an on-but-unconfigured source is listed greyed.
     */
    public function testSourcesListsOnlyOperatorEnabledSourcesWithConfiguredMirrors(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        $ids = array_column($data['sources'], 'id');
        // zlibrary + welib are switched off by the seeded config → not offered.
        self::assertSame(['annas_archive', 'libgen', 'torrent'], $ids);

        $byId = [];
        foreach ($data['sources'] as $s) {
            $byId[$s['id']] = $s;
        }
        self::assertSame(['https://aa.test'], $byId['annas_archive']['mirrors']);
        self::assertSame(['https://lg.test'], $byId['libgen']['mirrors']);
        self::assertTrue($byId['annas_archive']['enabled']);
        self::assertTrue($byId['libgen']['enabled']);
    }

    /** A source the operator enabled but never gave mirrors is listed, greyed out. */
    public function testSourcesListsEnabledButUnconfiguredSourceAsDisabled(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'welib', 'enabled' => true],
        ], ['annas_archive' => ['https://aa.test']]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $sources = $this->json()['sources'];
        self::assertSame(['annas_archive', 'welib'], array_column($sources, 'id'));
        self::assertTrue($sources[0]['enabled']);
        self::assertFalse($sources[1]['enabled']);
        self::assertSame([], $sources[1]['mirrors']);
    }

    /**
     * Operator-disabled sources are omitted entirely — the panel must not offer a
     * source the automatic cascade would refuse to use.
     */
    public function testSourcesOmitsOperatorDisabledSources(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => false],
            ['id' => 'zlibrary', 'enabled' => true],
            ['id' => 'welib', 'enabled' => true],
            ['id' => 'torrent', 'enabled' => false],
        ], ['annas_archive' => ['https://aa.test'], 'zlibrary' => ['https://zl.test']]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $ids = array_column($this->json()['sources'], 'id');
        self::assertSame(['annas_archive', 'zlibrary', 'welib'], $ids);
        self::assertNotContains('libgen', $ids);
        self::assertNotContains('torrent', $ids);
    }

    public function testSourcesFollowOperatorPriorityOrder(): void
    {
        // Operator re-prioritises Z-Library to the top; the panel must surface it
        // first so its auto-run search defaults to the highest-priority source.
        $this->saveConfig([
            ['id' => 'zlibrary', 'enabled' => true],
            ['id' => 'welib', 'enabled' => true],
            ['id' => 'torrent', 'enabled' => true],
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
        ], ['zlibrary' => ['https://zl.test']]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $ids = array_column($this->json()['sources'], 'id');
        self::assertSame(['zlibrary', 'welib', 'torrent', 'annas_archive', 'libgen'], $ids);
        // The frontend preselects the first element: the operator's top source.
        self::assertSame('zlibrary', $ids[0]);
    }

    /**
     * Torrent is a pickable source with no mirrors; once the operator switches it on
     * it is listed, but only `enabled` when the indexer + download-client stack is
     * configured (it isn't, in test).
     */
    public function testSourcesIncludesTorrentDisabledWithoutStack(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $torrent = null;
        foreach ($this->json()['sources'] as $s) {
            if ($s['id'] === 'torrent') {
                $torrent = $s;
            }
        }
        self::assertNotNull($torrent);
        self::assertSame('Torrent', $torrent['label']);
        self::assertFalse($torrent['enabled']);
        self::assertSame([], $torrent['mirrors']);
    }

    /** The torrent source needs no mirror — it 409s on an unconfigured stack instead. */
    public function testRunTorrentWithoutStackReturns409(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'torrent',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Torrent search is not configured.', $this->json()['error']);
    }

    /**
     * The torrent run path is 409-gated without a download stack, so the row mapper
     * is exercised directly: it turns a scored candidate's `extra` metadata into the
     * `torrent` block the panel renders.
     */
    public function testTorrentRowExposesTypeCategoriesAndPublishDate(): void
    {
        $row = $this->torrentRow([
            'leechers'    => 3,
            'flags'       => ['freeleech'],
            'categories'  => ['Audio', 'Audio/Audiobook'],
            'type'        => 'audiobook',
            'publishDate' => '2019-08-01',
        ]);

        self::assertSame('audiobook', $row['torrent']['type']);
        self::assertSame(['Audio', 'Audio/Audiobook'], $row['torrent']['categories']);
        self::assertSame('2019-08-01', $row['torrent']['published']);
        self::assertSame(3, $row['torrent']['leechers']);
        self::assertSame(['freeleech'], $row['torrent']['flags']);
        self::assertSame('MyAnonamouse', $row['torrent']['indexer']);
        self::assertSame(42, $row['torrent']['seeders']);
        self::assertSame(17, $row['torrent']['grabs']);
        self::assertSame(74, $row['torrent']['score']);
    }

    /** A row whose categories said nothing inherits the type the search asked for. */
    public function testTorrentRowFallsBackToThePlanContentTypeAndCapsCategories(): void
    {
        $row = $this->torrentRow([
            'type'       => null,
            'categories' => ['a', 'b', 'c', 'd', 'e'],
        ], 'ebook');

        self::assertSame('ebook', $row['torrent']['type']);
        self::assertSame(['a', 'b', 'c', 'd'], $row['torrent']['categories']);
        self::assertNull($row['torrent']['published']);
    }

    /** Missing extra keys degrade to empty/null rather than tripping the mapper. */
    public function testTorrentRowToleratesMissingExtras(): void
    {
        $row = $this->torrentRow([]);

        self::assertSame('audiobook', $row['torrent']['type']);
        self::assertSame([], $row['torrent']['categories']);
        self::assertNull($row['torrent']['published']);
        self::assertNull($row['torrent']['leechers']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function torrentRow(array $extra, string $planContentType = 'audiobook'): array
    {
        $candidate = new ReleaseCandidate(
            source: Integration::KIND_PROWLARR,
            sourceId: 'abc-123',
            title: 'Dungeon Crawler Carl',
            format: 'm4b',
            language: null,
            sizeBytes: 734003200,
            downloadUrl: 'magnet:?xt=urn:btih:deadbeef',
            infoUrl: 'https://indexer.example/details/1',
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: 'MyAnonamouse',
            seeders: 42,
            downloads: 17,
            contentType: $planContentType,
            author: null,
            isbns: [],
            publisher: null,
            year: '2020',
            extra: $extra,
        );
        $scored = new ScoredRelease($candidate, 0.74, ['match' => 0.9, 'seeders' => 1.0, 'size' => 1.0, 'format' => 1.0], 0);

        $method = new \ReflectionMethod(InteractiveSearchController::class, 'torrentRow');

        return $method->invoke(null, $scored, 80, $planContentType);
    }

    public function testGrabRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->postJson('/interactive-search/grab', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGrabRejectsUnresolvableBook(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/grab', [
            '_token' => $token,
            'id'     => 'guid-1',
            'title'  => 'Red Rising',
            'link'   => 'magnet:?xt=urn:btih:abc',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testGrabRejectsMissingLink(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/grab', [
            '_token' => $token,
            'bookId' => $this->seedBook()->getId(),
            'id'     => 'guid-1',
            'title'  => 'Red Rising',
            'link'   => '',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This result has no download link.', $this->json()['error']);
    }

    public function testGrabWithoutTorrentStackReturns409(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/grab', [
            '_token' => $token,
            'bookId' => $this->seedBook()->getId(),
            'id'     => 'guid-1',
            'title'  => 'Red Rising',
            'link'   => 'magnet:?xt=urn:btih:abc',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Torrent downloading is not configured.', $this->json()['error']);
    }

    private function seedBook(): Book
    {
        $existing = $this->em->getRepository(Book::class)
            ->findOneBy(['source' => Book::SOURCE_OPENLIBRARY, 'externalId' => 'OL-interactive-1']);
        if ($existing !== null) {
            return $existing;
        }

        $book = new Book(Book::SOURCE_OPENLIBRARY, 'OL-interactive-1', 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }

    public function testRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->postJson('/interactive-search/sources', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRunRejectsUnknownSource(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'not_a_source',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRunRejectsSourceWithoutMirror(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        // welib has no mirrors configured, and none supplied → 400.
        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'welib',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The stateful CSRF token is rendered into the book-modal partial; GET a page
     * that includes it so the client's session carries the matching token.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/browse');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-interactive-search-token-value]')->attr('data-interactive-search-token-value');
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
     * Two users: 'member' carries the per-user ROLE_INTERACTIVE_SEARCH permission the
     * controller is gated on (every functional test below drives the panel as them),
     * and 'plain' is a logged-in user without it — the 403 case.
     */
    private function seedUser(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User('member');
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $user->setRoles([User::ROLE_INTERACTIVE_SEARCH]);
        $this->em->persist($user);

        $plain = new User('plain');
        $plain->setPassword($hasher->hashPassword($plain, 'doesnt-matter'));
        $this->em->persist($plain);

        $this->em->flush();
    }

    private function seedConfig(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
            ['id' => 'zlibrary', 'enabled' => false],
            ['id' => 'welib', 'enabled' => false],
            ['id' => 'torrent', 'enabled' => true],
        ], [
            'annas_archive' => ['https://aa.test'],
            'libgen'        => ['https://lg.test'],
        ]);
    }

    /**
     * @param list<array{id: string, enabled: bool}> $priority
     * @param array<string, list<string>>            $mirrors
     */
    private function saveConfig(array $priority, array $mirrors): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => $priority,
            'mirrors'         => $mirrors,
        ], new MirrorListNormalizer());
        $this->integrations->saveDirectDownloadConfig($config, true, $this->em);
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
}
