<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Download\Client\DownloadClientInterface;
use App\Download\FulfillmentLog;
use App\Download\Mam\MamFulfillment;
use App\Download\Torrent\TorrentFulfillment;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Controller\InteractiveSearchController;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Mirror\MirrorListNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\ScoredRelease;
use App\Search\Torrent\TorrentMatchScorer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

        $this->em->createQuery('DELETE FROM '.DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM '.BookRequest::class)->execute();
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

    // --- ebook/audiobook resolution ---------------------------------------

    /**
     * The payload's explicit `audiobook` flag wins over the owned copy's format;
     * absent (legacy callers) or unparseable, the owned format decides, with a
     * null book meaning ebook.
     */
    #[DataProvider('audiobookResolutionProvider')]
    public function testResolveAudiobook(array $payload, ?string $bookFormat, bool $expected): void
    {
        $book = null;
        if ($bookFormat !== 'NO_BOOK') {
            $book = new Book('test', 'ext-resolve', 'Some Title');
            $book->setFormat($bookFormat);
        }

        $method = new \ReflectionMethod(InteractiveSearchController::class, 'resolveAudiobook');

        self::assertSame($expected, $method->invoke(null, $payload, static fn (): ?Book => $book));
    }

    /** @return iterable<string, array{array<string, mixed>, string|null, bool}> */
    public static function audiobookResolutionProvider(): iterable
    {
        // Flag present: it wins, whatever the book says.
        yield 'true beats ebook book'       => [['audiobook' => true], null, true];
        yield 'false beats audiobook book'  => [['audiobook' => false], 'm4b', false];
        yield 'int 1'                       => [['audiobook' => 1], null, true];
        yield 'int 0'                       => [['audiobook' => 0], 'm4b', false];
        yield 'string 1'                    => [['audiobook' => '1'], null, true];
        yield 'string 0'                    => [['audiobook' => '0'], 'm4b', false];
        yield 'string true'                 => [['audiobook' => 'true'], null, true];
        yield 'string false'                => [['audiobook' => 'false'], 'm4b', false];
        // Flag absent or unusable: fall back to the owned copy's format.
        yield 'absent, owned audiobook'     => [[], 'm4b', true];
        yield 'absent, owned ebook'         => [[], 'epub', false];
        yield 'absent, format unknown'      => [[], null, false];
        yield 'absent, no book'             => [[], 'NO_BOOK', false];
        yield 'null value, owned audiobook' => [['audiobook' => null], 'm4b', true];
        yield 'garbage value, no book'      => [['audiobook' => 'banana'], 'NO_BOOK', false];
    }

    /**
     * Audiobook mode reaches the direct-HTTP sources as an audiobook plan: the
     * advertised `ext=` facets switch to the audio list — even for a book that is
     * not in the library at all (no bookId), where the old owned-format guess
     * could only ever say "ebook".
     */
    public function testRunPayloadAudiobookFlagSwitchesDirectSearchToAudioFormats(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token'    => $token,
            'source'    => 'annas_archive',
            'title'     => 'Red Rising',
            'author'    => 'Pierce Brown',
            'audiobook' => true,
        ]);

        self::assertResponseIsSuccessful();
        $url = (string) $this->json()['searchUrl'];
        self::assertStringContainsString('ext=mp3', $url);
        self::assertStringContainsString('ext=m4b', $url);
        self::assertStringNotContainsString('ext=epub', $url);
    }

    /** Without the flag, a legacy caller still gets the ebook search it always got. */
    public function testRunWithoutFlagDefaultsToEbookFormats(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'annas_archive',
            'title'  => 'Red Rising',
            'author' => 'Pierce Brown',
        ]);

        self::assertResponseIsSuccessful();
        $url = (string) $this->json()['searchUrl'];
        self::assertStringContainsString('ext=epub', $url);
        self::assertStringNotContainsString('ext=mp3', $url);
    }

    /** Absent flag + owned audio file: the legacy format derivation still applies. */
    public function testRunWithoutFlagFallsBackToOwnedAudiobookFormat(): void
    {
        $book = $this->seedBook(format: 'm4b');

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'annas_archive',
            'bookId' => $book->getId(),
            'title'  => 'Red Rising',
        ]);

        self::assertResponseIsSuccessful();
        $url = (string) $this->json()['searchUrl'];
        self::assertStringContainsString('ext=m4b', $url);
        self::assertStringNotContainsString('ext=epub', $url);
    }

    /** An explicit false wins over an owned audio file — the user asked for the ebook. */
    public function testRunPayloadFlagFalseWinsOverOwnedAudiobookFormat(): void
    {
        $book = $this->seedBook(format: 'm4b');

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token'    => $token,
            'source'    => 'annas_archive',
            'bookId'    => $book->getId(),
            'title'     => 'Red Rising',
            'audiobook' => false,
        ]);

        self::assertResponseIsSuccessful();
        $url = (string) $this->json()['searchUrl'];
        self::assertStringContainsString('ext=epub', $url);
        self::assertStringNotContainsString('ext=m4b', $url);
    }

    /**
     * The grab request lookup is scoped by the resolved flag: an audiobook grab
     * for a book that already has an ebook request creates a SEPARATE audiobook
     * request instead of hijacking the ebook one.
     */
    public function testGrabScopesRequestLookupByPayloadAudiobookFlag(): void
    {
        $this->client->disableReboot();
        $this->stubTorrents();

        $user = $this->loadUser();
        $book = $this->seedBook();
        $ebookRequest = new BookRequest($user, $book);
        $this->em->persist($ebookRequest);
        $this->em->flush();

        $this->client->loginUser($user);
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/grab', [
            '_token'    => $token,
            'bookId'    => $book->getId(),
            'id'        => 'guid-1',
            'title'     => 'Red Rising (Unabridged)',
            'link'      => 'magnet:?xt=urn:btih:abc',
            'audiobook' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);

        $this->em->clear();
        $requests = $this->em->getRepository(BookRequest::class)
            ->findBy(['requestedBy' => $user->getId(), 'book' => $book->getId()]);
        self::assertCount(2, $requests);

        $byFormat = [];
        foreach ($requests as $r) {
            $byFormat[$r->isAudiobook() ? 'audiobook' : 'ebook'] = $r;
        }
        // The new audiobook request was approved; the ebook one was left alone.
        self::assertSame(BookRequest::STATUS_APPROVED, $byFormat['audiobook']->getStatus());
        self::assertSame(BookRequest::STATUS_PENDING, $byFormat['ebook']->getStatus());
    }

    /**
     * Without the flag a grab falls back to the owned-format derivation (ebook
     * here) and reuses the existing ebook request rather than creating a second.
     */
    public function testGrabWithoutFlagReusesExistingEbookRequest(): void
    {
        $this->client->disableReboot();
        $this->stubTorrents();

        $user = $this->loadUser();
        $book = $this->seedBook();
        $ebookRequest = new BookRequest($user, $book);
        $this->em->persist($ebookRequest);
        $this->em->flush();

        $this->client->loginUser($user);
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/grab', [
            '_token' => $token,
            'bookId' => $book->getId(),
            'id'     => 'guid-1',
            'title'  => 'Red Rising',
            'link'   => 'magnet:?xt=urn:btih:abc',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);

        $this->em->clear();
        $requests = $this->em->getRepository(BookRequest::class)
            ->findBy(['requestedBy' => $user->getId(), 'book' => $book->getId()]);
        self::assertCount(1, $requests);
        self::assertFalse($requests[0]->isAudiobook());
        self::assertSame(BookRequest::STATUS_APPROVED, $requests[0]->getStatus());
    }

    /**
     * Replace the torrent stack with one that accepts every grab, under the
     * concrete service id the compiled container wired into the controller.
     */
    private function stubTorrents(): void
    {
        $torrents = $this->createStub(TorrentFulfillmentInterface::class);
        $torrents->method('isAvailable')->willReturn(true);
        $torrents->method('grab')->willReturn(true);
        self::getContainer()->set(TorrentFulfillment::class, $torrents);
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

    /**
     * The owned-copy format is always (re)stamped, because books survive between
     * tests and test runs: a leftover format from another test must not leak into
     * the audiobook-fallback derivation under test. Seeding happens before any
     * client request — afterwards the kernel reboot resets Doctrine and a flush
     * on the now-detached entity would be a silent no-op.
     */
    private function seedBook(?string $format = null): Book
    {
        $book = $this->em->getRepository(Book::class)
            ->findOneBy(['source' => Book::SOURCE_OPENLIBRARY, 'externalId' => 'OL-interactive-1']);
        if ($book === null) {
            $book = new Book(Book::SOURCE_OPENLIBRARY, 'OL-interactive-1', 'Red Rising');
            $book->setAuthor('Pierce Brown');
            $this->em->persist($book);
        }
        $book->setFormat($format);
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

    // --- MyAnonamouse source ----------------------------------------------

    /**
     * With the integration + a torrent client available, the mam source entry is
     * enabled, mirror-less, seeded with the shared search-method default, and
     * carries the wedge context the panel's toggle needs.
     */
    public function testSourcesIncludesMamWithWedgeBlockWhenAvailable(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam(alwaysUseWedge: true, autoWedgeMinGb: 2.5, isVip: true);
        $this->stubMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $mam = $this->sourceEntry('mam');
        self::assertNotNull($mam);
        self::assertSame('MyAnonamouse', $mam['label']);
        self::assertTrue($mam['enabled']);
        self::assertSame([], $mam['mirrors']);
        self::assertSame('categories', $mam['searchMethod']);
        self::assertSame([
            'userIsVip' => true,
            'alwaysUse' => true,
            'autoMinGb' => 2.5,
        ], $mam['wedge']);
    }

    /**
     * Switched on in the priority list but never configured (no cookie, no MAM
     * integration): listed greyed out, with a default wedge block — exactly like
     * the torrent source without its stack.
     */
    public function testSourcesListsMamDisabledWhenUnconfigured(): void
    {
        $this->saveConfigWithMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $mam = $this->sourceEntry('mam');
        self::assertNotNull($mam);
        self::assertFalse($mam['enabled']);
        self::assertSame([], $mam['mirrors']);
        self::assertSame([
            'userIsVip' => false,
            'alwaysUse' => false,
            'autoMinGb' => null,
        ], $mam['wedge']);
    }

    public function testRunMamWithoutConfigReturns409(): void
    {
        $this->saveConfigWithMam();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'mam',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('MyAnonamouse search is not configured.', $this->json()['error']);
    }

    /**
     * MAM rows come back in the torrent row shape (the panel's torrent table
     * renders them unchanged) plus a `mam` block: alreadyFree applies the
     * VIP-aware freeleech rule, wedgeDefault the auto-wedge size threshold.
     */
    public function testRunMamReturnsTorrentShapedRowsWithMamBlock(): void
    {
        $this->saveConfigWithMam();
        // VIP account; auto-wedge from 0.05 GB (the 100 MiB rows are above it).
        $this->seedMam(autoWedgeMinGb: 0.05, isVip: true);
        $this->stubMam(searchRows: [
            $this->mamSearchRow(id: 111, dl: 'dl-a', free: true),
            $this->mamSearchRow(id: 222, dl: 'dl-b', flVip: true),
            $this->mamSearchRow(id: 333, dl: 'dl-c'),
        ]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'mam',
            'title'  => 'Red Rising',
            'author' => 'Pierce Brown',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame('mam', $data['source']);
        self::assertNull($data['mirror']);
        self::assertStringContainsString('/tor/browse.php', (string) $data['searchUrl']);

        $rows = [];
        foreach ($data['results'] as $row) {
            $rows[$row['id']] = $row;
        }
        self::assertCount(3, $rows);

        foreach ($rows as $row) {
            self::assertSame('MyAnonamouse', $row['torrent']['indexer']);
        }

        // Sitewide freeleech: already free, never wedge.
        self::assertTrue($rows['111']['mam']['free']);
        self::assertTrue($rows['111']['mam']['alreadyFree']);
        self::assertFalse($rows['111']['mam']['wedgeDefault']);
        self::assertSame('dl-a', $rows['111']['mam']['dlHash']);
        self::assertSame(111, $rows['111']['mam']['torrentId']);
        self::assertContains('freeleech', $rows['111']['torrent']['flags']);

        // VIP freeleech + VIP account: already free too.
        self::assertTrue($rows['222']['mam']['flVip']);
        self::assertTrue($rows['222']['mam']['alreadyFree']);
        self::assertFalse($rows['222']['mam']['wedgeDefault']);
        self::assertContains('vip_freeleech', $rows['222']['torrent']['flags']);

        // Plain release above the auto-wedge threshold: wedge on by default.
        self::assertFalse($rows['333']['mam']['alreadyFree']);
        self::assertTrue($rows['333']['mam']['wedgeDefault']);
    }

    /**
     * For a non-VIP account a VIP-freeleech release still costs ratio, and
     * "always use wedge" flips every not-free row's default on — but never a
     * sitewide-freeleech row's.
     */
    public function testRunMamWedgeDefaultRespectsAlwaysUseWedgeForNonVip(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam(alwaysUseWedge: true, isVip: false);
        $this->stubMam(searchRows: [
            $this->mamSearchRow(id: 111, dl: 'dl-a', free: true),
            $this->mamSearchRow(id: 222, dl: 'dl-b', flVip: true),
        ]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'mam',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseIsSuccessful();
        $rows = [];
        foreach ($this->json()['results'] as $row) {
            $rows[$row['id']] = $row;
        }

        self::assertTrue($rows['111']['mam']['alreadyFree']);
        self::assertFalse($rows['111']['mam']['wedgeDefault']);

        self::assertFalse($rows['222']['mam']['alreadyFree'], 'VIP freeleech is not free for a non-VIP account');
        self::assertTrue($rows['222']['mam']['wedgeDefault'], 'alwaysUseWedge covers every not-free release');
    }

    /** An unrecognized method falls back to categories — MAM gets a main_cat scope. */
    public function testRunMamInvalidMethodFallsBackToCategories(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam(searchRows: [$this->mamSearchRow(id: 111, dl: 'dl-a')]);

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/run', [
            '_token'       => $token,
            'source'       => 'mam',
            'title'        => 'Red Rising',
            'searchMethod' => 'bogus',
        ]);

        self::assertResponseIsSuccessful();
        $search = $this->mamRequestContaining('loadSearchJSONbasic.php');
        self::assertNotNull($search);
        self::assertStringContainsString('tor[main_cat][0]=14', urldecode($search), 'ebook default under the categories method');
    }

    /**
     * A MAM grab creates a source='mam' torrent-protocol job, spends the wedge
     * (useWedge=true, not free) BEFORE fetching the .torrent, and stamps the
     * client hash — the async poller takes it from there.
     */
    public function testGrabMamCreatesMamJobAndSpendsWedge(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam();
        $book = $this->seedBook();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/grab', $this->mamGrabPayload($token, $book, useWedge: true));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);

        $this->em->clear();
        $jobs = $this->em->getRepository(DownloadJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertSame('mam', $jobs[0]->getSource());
        self::assertSame('123456', $jobs[0]->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $jobs[0]->getProtocol());
        self::assertSame('added-hash', $jobs[0]->getClientRef());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $jobs[0]->getStatus());

        $joined = implode("\n", $this->mamRequests);
        self::assertStringContainsString('bonusBuy.php', $joined);
        self::assertStringContainsString('spendtype=personalFL&torrentid=123456', $joined);
        self::assertStringContainsString('/tor/download.php/dl-hash-abc', $joined);
    }

    /**
     * The posted wedge flag is re-validated server-side: a release that is
     * already free for this account (here VIP freeleech + VIP user, recomputed
     * from the account snapshot) never spends a wedge, whatever the client said.
     */
    public function testGrabMamForcesWedgeOffWhenAlreadyFree(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam(isVip: true);
        $this->stubMam();
        $book = $this->seedBook();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/grab', $this->mamGrabPayload($token, $book, useWedge: true, flVip: true));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);
        self::assertStringNotContainsString('bonusBuy.php', implode("\n", $this->mamRequests), 'never wedge an already-free release');
    }

    /** "Always use wedge" wins over an unchecked toggle on a not-free release. */
    public function testGrabMamForcesWedgeOnWhenAlwaysUseWedge(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam(alwaysUseWedge: true);
        $this->stubMam();
        $book = $this->seedBook();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/grab', $this->mamGrabPayload($token, $book, useWedge: false));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['queued']);
        self::assertStringContainsString('bonusBuy.php', implode("\n", $this->mamRequests), 'alwaysUseWedge overrides the posted choice');
    }

    public function testGrabMamWithoutConfigReturns409(): void
    {
        $this->saveConfigWithMam();
        $book = $this->seedBook();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/grab', $this->mamGrabPayload($token, $book, useWedge: false));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('MyAnonamouse downloading is not configured.', $this->json()['error']);
    }

    public function testGrabMamRejectsMissingMamBlock(): void
    {
        $this->saveConfigWithMam();
        $this->seedMam();
        $this->stubMam();
        $book = $this->seedBook();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $payload = $this->mamGrabPayload($token, $book, useWedge: false);
        unset($payload['mam']);
        $this->postJson('/interactive-search/grab', $payload);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This result is missing its MyAnonamouse download data.', $this->json()['error']);
    }

    // --- MAM helpers ------------------------------------------------------

    /** Requested MAM URLs + form bodies ("url|body"), for asserting on wedge/search calls. */
    private array $mamRequests = [];

    /** The seeded config, with mam appended enabled. */
    private function saveConfigWithMam(): void
    {
        $this->saveConfig([
            ['id' => 'annas_archive', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
            ['id' => 'zlibrary', 'enabled' => false],
            ['id' => 'welib', 'enabled' => false],
            ['id' => 'torrent', 'enabled' => true],
            ['id' => 'mam', 'enabled' => true],
        ], [
            'annas_archive' => ['https://aa.test'],
            'libgen'        => ['https://lg.test'],
        ]);
    }

    /** Enabled MAM integration: config + session cookie + account snapshot. */
    private function seedMam(bool $alwaysUseWedge = false, ?float $autoWedgeMinGb = null, bool $isVip = false): void
    {
        $config = new MyAnonamouseConfig(
            enabled: true,
            baseUrl: 'https://mam.test',
            alwaysUseWedge: $alwaysUseWedge,
            autoWedgeMinGb: $autoWedgeMinGb,
        );
        $this->integrations->saveMyAnonamouseConfig($config, true, $this->em);
        $this->em->flush();
        $this->integrations->persistRotatedMamSessionCookie('test-cookie');
        $this->integrations->saveMamAccountState(['isVip' => $isVip]);
    }

    /**
     * Swap the MAM stack for one that answers from canned rows over a mocked HTTP
     * layer. MamFulfillment is final, so a REAL instance is wired (mock transport,
     * stub torrent client) and set under the concrete ids the controller injects
     * — the same pattern as stubTorrents(), one level deeper.
     *
     * @param list<array<string, mixed>> $searchRows
     */
    private function stubMam(array $searchRows = []): void
    {
        $this->client->disableReboot();
        $this->mamRequests = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($searchRows): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $this->mamRequests[] = $url . '|' . $body;
            if (str_contains($url, 'loadSearchJSONbasic.php')) {
                return new MockResponse(json_encode(['data' => $searchRows, 'found' => \count($searchRows)], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, 'bonusBuy.php')) {
                return new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR));
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
     * The /sources entry with this id from the last response, or null.
     *
     * @return array<string, mixed>|null
     */
    private function sourceEntry(string $id): ?array
    {
        foreach ($this->json()['sources'] as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }

        return null;
    }

    /** First recorded MAM request ("url|body") whose URL matches, or null. */
    private function mamRequestContaining(string $needle): ?string
    {
        foreach ($this->mamRequests as $request) {
            if (str_contains($request, $needle)) {
                return $request;
            }
        }

        return null;
    }

    /**
     * One MAM search-endpoint row in the raw JSON shape mapRelease() consumes
     * (mirrors MamFulfillmentTest::searchRow).
     *
     * @return array<string, mixed>
     */
    private function mamSearchRow(int $id, string $dl, bool $free = false, bool $flVip = false): array
    {
        return [
            'id'                 => $id,
            'title'              => 'Red Rising',
            'author_info'        => json_encode(['1' => 'Pierce Brown'], JSON_THROW_ON_ERROR),
            'main_cat'           => 14,
            'catname'            => 'Ebooks - Fiction',
            'filetypes'          => 'epub',
            'size'               => '100 MiB',
            'seeders'            => 10,
            'leechers'           => 1,
            'times_completed'    => 5,
            'vip'                => 0,
            'fl_vip'             => $flVip ? 1 : 0,
            'free'               => $free ? 1 : 0,
            'personal_freeleech' => 0,
            'dl'                 => $dl,
            'added'              => '2024-05-01 12:34:56',
        ];
    }

    /**
     * The grab payload the panel posts for a picked MAM row.
     *
     * @return array<string, mixed>
     */
    private function mamGrabPayload(string $token, Book $book, bool $useWedge, bool $free = false, bool $flVip = false): array
    {
        return [
            '_token'   => $token,
            'source'   => 'mam',
            'bookId'   => $book->getId(),
            'id'       => '123456',
            'title'    => 'Red Rising',
            'link'     => 'https://mam.test/tor/download.php/dl-hash-abc',
            'format'   => 'epub',
            'indexer'  => 'MyAnonamouse',
            'seeders'  => 10,
            'useWedge' => $useWedge,
            'mam'      => [
                'torrentId'         => 123456,
                'dlHash'            => 'dl-hash-abc',
                'free'              => $free,
                'flVip'             => $flVip,
                'personalFreeleech' => false,
            ],
        ];
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
