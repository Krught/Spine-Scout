<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the manual-fulfillment surface on the requests page: the passive
 * automatic-fulfillment state indicator (the switch itself lives in
 * Settings → General), and the "next item awaiting manual fulfillment" queue
 * the interactive-search overlay walks when the pipeline is off.
 */
final class RequestsControllerTest extends WebTestCase
{
    private const CSRF_ID = 'requests_pipeline';

    /** Mirrors RequestsController::PER_PAGE. */
    private const PER_PAGE = 50;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;
    private CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->integrations = $c->get(IntegrationRepository::class);
        $this->cache = $c->get(CacheItemPoolInterface::class);
        // Cover resolution is cache-first, so a leftover entry from a sibling test
        // would mask the DB/upstream paths these tests are asserting on.
        $this->cache->clear();

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('admin-pipeline', [User::ROLE_ADMIN]);
        $this->seedUser('member-pipeline', []);
        $this->em->clear();
    }

    public function testManualNextReturnsOldestAwaitingRequestFirst(): void
    {
        $first = $this->seedRequest('First Work');
        $this->seedRequest('Second Work');

        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $this->postJson('/requests/manual-next', []);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertFalse($data['done']);
        self::assertSame($first->getId(), $data['request']['id']);
        self::assertSame('First Work', $data['request']['title']);
        self::assertSame('Matt Dinniman', $data['request']['author']);
        self::assertSame('grimmory', $data['request']['bookSource']);
        self::assertNotEmpty($data['request']['externalId']);
        self::assertSame('admin-pipeline', $data['request']['requestedBy']);
        self::assertFalse($data['request']['audiobook']);
        self::assertNotNull($data['request']['bookId']);
    }

    public function testManualNextAdvancesPastTheAfterId(): void
    {
        $first = $this->seedRequest('First Work');
        $second = $this->seedRequest('Second Work');

        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $this->postJson('/requests/manual-next', ['after' => $first->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame($second->getId(), $this->json()['request']['id']);
    }

    public function testManualNextReturnsDoneWhenQueueIsExhausted(): void
    {
        $only = $this->seedRequest('Only Work');

        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $this->postJson('/requests/manual-next', ['after' => $only->getId()]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['done']);
        self::assertArrayNotHasKey('request', $this->json());
    }

    public function testManualNextReturnsDoneWhenNothingIsAwaiting(): void
    {
        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $this->postJson('/requests/manual-next', []);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['done']);
    }

    /**
     * The queue uses the same "still needs a search" notion as the automatic
     * retry sweep: a completed or in-flight download takes the request out;
     * an errored one leaves it in.
     */
    public function testManualNextSkipsDeliveredAndInFlightRequestsButKeepsErrored(): void
    {
        $delivered = $this->seedRequest('Delivered Work');
        $this->seedJob($delivered, DownloadJob::STATUS_COMPLETE);
        $inFlight = $this->seedRequest('Downloading Work');
        $this->seedJob($inFlight, DownloadJob::STATUS_DOWNLOADING);
        $errored = $this->seedRequest('Errored Work');
        $this->seedJob($errored, DownloadJob::STATUS_ERROR);
        $pending = $this->seedRequest('Not Yet Approved Work', BookRequest::STATUS_PENDING);

        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $this->postJson('/requests/manual-next', []);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertFalse($data['done']);
        self::assertSame($errored->getId(), $data['request']['id']);

        // …and nothing after it: the pending request is not awaiting *manual
        // fulfillment*, it is awaiting approval.
        $this->postJson('/requests/manual-next', ['after' => $errored->getId()]);
        self::assertTrue($this->json()['done']);
        self::assertNotSame($pending->getId(), $errored->getId());
    }

    public function testManualNextRejectsInvalidCsrfToken(): void
    {
        $this->seedRequest('First Work');
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $this->postJson('/requests/manual-next', [], 'not-a-valid-token');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexShowsPassivePipelineState(): void
    {
        $this->integrations->setAutomaticFulfillmentEnabled(false);
        $this->em->clear();

        $this->client->loginUser($this->loadUser('admin-pipeline'));
        $crawler = $this->client->request('GET', '/requests');

        // The switch itself moved to Settings → General; the page renders the
        // current state as a plain passive indicator (no link, no control), and
        // the manual-queue entry point is live while the pipeline is off.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.requests-pipeline-state', 'Automatic fulfillment: off');
        self::assertCount(0, $crawler->filter('.requests-pipeline-state a'));
        self::assertCount(0, $crawler->filter('.requests-pipeline-state input'));
        self::assertCount(0, $crawler->filter('[data-request-search-toggle-url-value]'));
        self::assertNull($crawler->filter('.requests-queue-start')->attr('hidden'));

        $this->integrations->setAutomaticFulfillmentEnabled(true);
        $this->em->clear();

        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.requests-pipeline-state', 'Automatic fulfillment: on');
        self::assertNotNull($crawler->filter('.requests-queue-start')->attr('hidden'));
    }

    public function testIndexHidesPipelineStateFromUsersWithoutManageSettings(): void
    {
        $this->client->loginUser($this->loadUser('member-pipeline'));
        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.requests-pipeline-state'));
        self::assertCount(0, $crawler->filter('.requests-queue-start'));
    }

    public function testIndexPaginatesAndClampsThePageParameter(): void
    {
        // One page-worth plus three, so page 2 holds exactly the three oldest.
        $titles = [];
        for ($i = 1; $i <= self::PER_PAGE + 3; $i++) {
            $titles[] = 'Paged Work ' . $i;
            $this->seedRequest('Paged Work ' . $i);
        }
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $page1 = $this->client->request('GET', '/requests');
        self::assertResponseIsSuccessful();
        self::assertCount(self::PER_PAGE, $page1->filter('li.request-row'));
        // Newest first: the three oldest are *not* on page 1.
        self::assertStringNotContainsString('Paged Work 1<', $this->html());
        self::assertStringContainsString('data-requests-scroll-page-value="1"', $this->html());
        self::assertStringContainsString('data-requests-scroll-pages-value="2"', $this->html());
        // More pages remain, so the endless-scroll sentinel is present.
        self::assertCount(1, $page1->filter('.requests-scroll-sentinel'));

        $page2 = $this->client->request('GET', '/requests?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $page2->filter('li.request-row'));
        self::assertStringContainsString('Paged Work 1<', $this->html());
        // Last page: nothing left to scroll into.
        self::assertCount(0, $page2->filter('.requests-scroll-sentinel'));

        // Out-of-range and junk page values clamp into the valid range.
        $this->client->request('GET', '/requests?page=99');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-requests-scroll-page-value="2"', $this->html());

        $this->client->request('GET', '/requests?page=0');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-requests-scroll-page-value="1"', $this->html());

        $this->client->request('GET', '/requests?page=not-a-number');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-requests-scroll-page-value="1"', $this->html());
    }

    /**
     * The endless-scroll fetch: asking the index route for application/json
     * answers the requested page as row HTML plus paging facts, instead of a
     * full document.
     */
    public function testIndexAnswersJsonRowsForTheEndlessScrollFetch(): void
    {
        for ($i = 1; $i <= self::PER_PAGE + 3; $i++) {
            $this->seedRequest('Paged Work ' . $i);
        }
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $this->client->request('GET', '/requests?page=2', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame(2, $data['page']);
        self::assertSame(2, $data['pages']);
        self::assertSame(self::PER_PAGE + 3, $data['total']);
        self::assertSame(3, substr_count($data['rows'], 'request-row'), 'Page 2 holds exactly the three oldest rows.');
        self::assertStringContainsString('Paged Work 1<', $data['rows']);
    }

    public function testIndexHidesTheScrollSentinelWhenEverythingFitsOnOnePage(): void
    {
        $this->seedRequest('Only Work');
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('li.request-row'));
        self::assertCount(0, $crawler->filter('.requests-scroll-sentinel'));
    }

    /**
     * A book whose cover URL is already persisted must render from the DB alone. No
     * Hardcover integration exists here, so an upstream lookup could only ever produce
     * the placeholder — a real cover image proves the DB column was consulted first.
     */
    public function testIndexUsesThePersistedCoverUrlWithoutAnUpstreamLookup(): void
    {
        $request = $this->seedRequest('Stored Cover Work', BookRequest::STATUS_APPROVED, 'hardcover', 'https://example.test/cover.jpg');
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        $img = $crawler->filter('li.request-row img.request-cover');
        self::assertCount(1, $img);
        self::assertMatchesRegularExpression('#/cover/[a-f0-9]{40}$#', (string) $img->attr('src'));
        self::assertCount(0, $crawler->filter('.request-cover-placeholder'));
        // …and the resolved proxy URL is primed into the cache for the next render.
        $item = $this->cache->getItem('book.cover.' . $request->getBook()->getId());
        self::assertTrue($item->isHit());
        self::assertSame($img->attr('src'), $item->get());
    }

    /**
     * Coverless books cost at most MAX_REMOTE_COVER_FETCHES upstream attempts per render;
     * each attempt that comes back empty is cached negatively so it isn't repeated. Rows
     * past the budget are left uncached (placeholder now, retried on the next load).
     */
    public function testIndexBoundsUpstreamCoverLookupsAndCachesMisses(): void
    {
        $books = [];
        for ($i = 1; $i <= 5; $i++) {
            $books[] = $this->seedRequest('Coverless Work ' . $i, BookRequest::STATUS_APPROVED, 'hardcover')->getBook();
        }
        $this->client->loginUser($this->loadUser('admin-pipeline'));

        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.request-cover-placeholder'));

        $misses = 0;
        foreach ($books as $book) {
            $item = $this->cache->getItem('book.cover.' . $book->getId());
            if ($item->isHit()) {
                self::assertFalse($item->get(), 'A coverless book caches the miss sentinel, not a URL.');
                ++$misses;
            }
        }
        self::assertSame(3, $misses, 'At most three upstream cover lookups per page render.');
    }

    /**
     * `requests_pipeline` is a stateful (session-backed) CSRF id, so seed the
     * token into the client's session rather than scraping it out of a template
     * this test does not own.
     */
    private function primeCsrfToken(): string
    {
        $token = 'test-pipeline-token';
        $this->client->request('GET', '/requests');
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/' . self::CSRF_ID, $token);
        $session->save();

        return $token;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $path, array $payload, ?string $token = null): void
    {
        $payload['_token'] = $token ?? $this->primeCsrfToken();

        $this->client->request(
            'POST',
            $path,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedRequest(
        string $title,
        string $status = BookRequest::STATUS_APPROVED,
        string $source = 'grimmory',
        ?string $coverUrl = null,
    ): BookRequest {
        $book = new Book($source, 'ext-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor('Matt Dinniman');
        if ($coverUrl !== null) {
            $book->setCoverUrl($coverUrl);
        }
        $request = new BookRequest($this->loadUser('admin-pipeline'), $book);
        $request->setStatus($status);

        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedJob(BookRequest $request, string $status): DownloadJob
    {
        $job = new DownloadJob('pending', '', 'http', $request);
        $job->setStatus($status);
        $request->setDeliveryStatus($status);
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    /** @param list<string> $roles */
    private function seedUser(string $username, array $roles): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        if ($roles !== []) {
            $user->setRoles($roles);
        }
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
