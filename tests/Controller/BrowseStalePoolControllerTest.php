<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\Hardcover\HardcoverClient;
use App\Service\BrowsePoolCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stale-while-revalidate on the browse pools.
 *
 * Two regressions are pinned here. A Hardcover HTTP 429 used to blank a browse page we had
 * already loaded, because the cached pool expired and the errored refresh had nothing to put in
 * its place — the pool now never expires at all. And a stale pool used to be rebuilt *inside*
 * the request, so the reader waited on Hardcover (and ate the 429 latency); it is now served
 * immediately and rebuilt by a worker via {@see \App\Message\RefreshBrowsePool}.
 */
final class BrowseStalePoolControllerTest extends WebTestCase
{
    /** Must match BrowsePoolCache::CACHE_KEY / SEARCH_CACHE_PREFIX. */
    private const TRENDING_KEY = 'browse.trending.v3';
    private const SEARCH_PREFIX = 'browse.search.v4.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CacheItemPoolInterface $cache;
    /**
     * One long-lived mock transport. The container refuses to replace an already-initialized
     * service, so the stubbed HardcoverClient is installed once and each stub* helper swaps
     * the transport's response factory instead.
     */
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The stubbed HardcoverClient and the warm cache must survive across requests.
        $this->client->disableReboot();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->cache = $c->get(CacheItemPoolInterface::class);
        $this->cache->clear();

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookSectionEntry::class)->execute();
        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        // The async transport is the real Doctrine one in tests, so the queue table is where we
        // observe (and count) background refreshes.
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
        $this->seedUser('browse-user');
        $this->seedHardcoverIntegration();
        $this->em->clear();

        $this->http = new MockHttpClient();
        $c->set(HardcoverClient::class, new HardcoverClient($this->http, new ArrayAdapter(), new NullLogger()));
    }

    /**
     * The headline behaviour: past the threshold the reader gets the saved rows with no upstream
     * call at all, and exactly one worker refresh is queued no matter how many times they scroll.
     */
    public function testStalePoolIsServedWithoutUpstreamAndQueuesExactlyOneRefresh(): void
    {
        $this->stubTrending(['Warm One', 'Warm Two']);
        $this->client->loginUser($this->loadUser('browse-user'));

        $fresh = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One', 'Warm Two'], array_column($fresh['items'], 'title'));
        self::assertFalse($fresh['stale']);
        self::assertSame(0, $this->queuedRefreshes(), 'a cold fetch has nothing to revalidate');
        $callsWhileFresh = $this->http->getRequestsCount();

        $this->agePool(self::TRENDING_KEY);

        $stale = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One', 'Warm Two'], array_column($stale['items'], 'title'));
        self::assertTrue($stale['stale']);
        // Not an upstream failure — the rows are simply older than the admin threshold.
        self::assertTrue($stale['refreshing']);
        self::assertSame($callsWhileFresh, $this->http->getRequestsCount(), 'a stale pool must not call upstream in-request');
        self::assertSame(1, $this->queuedRefreshes());

        // Dispatch storm guard: the in-flight marker keeps the second scroll off the queue.
        $second = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One', 'Warm Two'], array_column($second['items'], 'title'));
        self::assertTrue($second['stale']);
        self::assertSame(1, $this->queuedRefreshes(), 'a second request queued another refresh');
        self::assertSame($callsWhileFresh, $this->http->getRequestsCount());
    }

    /** A fresh pool is just served: no refresh queued, no upstream call. */
    public function testFreshPoolServesWithoutDispatchingAnything(): void
    {
        $this->stubTrending(['Warm One']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');
        $calls = $this->http->getRequestsCount();

        $again = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One'], array_column($again['items'], 'title'));
        self::assertFalse($again['stale']);
        self::assertFalse($again['refreshing']);
        self::assertSame(0, $this->queuedRefreshes());
        self::assertSame($calls, $this->http->getRequestsCount());
    }

    /**
     * The pool used to die at a 6h cache TTL. It now carries no expiry at all, so an operator
     * syncing once a day (or an upstream down for a week) still gets their saved rows.
     */
    public function testPoolNeverExpires(): void
    {
        $this->stubTrending(['Warm One']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');

        // Asserted behaviourally: the filesystem adapter doesn't hand an item's expiry back on
        // read (getMetadata() is empty), so there is nothing to inspect — what matters is that a
        // pool far older than the old 6h ceiling is still a cache hit and is still served.
        $this->agePool(self::TRENDING_KEY, 30 * 86400);
        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One'], array_column($data['items'], 'title'));
        self::assertTrue($data['stale']);
    }

    /**
     * The worker half: a 429 during the background rebuild must leave the stored pool exactly as
     * it was (never blank it, never shrink it) and stamp the failure so we back off.
     */
    public function testA429InTheHandlerLeavesTheStoredPoolUntouched(): void
    {
        $this->stubTrending(['Warm One', 'Warm Two']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');
        $this->agePool(self::TRENDING_KEY);

        $this->stubRateLimited();
        $this->handleRefresh(new \App\Message\RefreshBrowsePool());

        $pool = $this->storedPool(self::TRENDING_KEY);
        self::assertSame(['Warm One', 'Warm Two'], array_column($pool['books'], 'title'), 'a 429 wiped the warm pool');
        self::assertNotNull($pool['failedAt'], 'a failed rebuild must stamp the backoff');

        // And the reader still gets the rows, flagged stale, with no upstream call of their own.
        $calls = $this->http->getRequestsCount();
        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Warm One', 'Warm Two'], array_column($data['items'], 'title'));
        self::assertTrue($data['stale']);
        self::assertSame($calls, $this->http->getRequestsCount());
        // Backoff: no new refresh queued straight after a failure.
        self::assertSame(0, $this->queuedRefreshes());
    }

    /** A successful worker rebuild replaces the rows and clears the stale flag for the next read. */
    public function testASuccessfulBackgroundRefreshReplacesThePool(): void
    {
        $this->stubTrending(['Old One', 'Old Two']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');
        $this->agePool(self::TRENDING_KEY);

        $this->stubTrending(['New One', 'New Two']);
        $this->handleRefresh(new \App\Message\RefreshBrowsePool());

        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['New One', 'New Two'], array_column($data['items'], 'title'));
        self::assertFalse($data['stale']);
    }

    /**
     * A rebuild that comes back shorter than the pool we hold must not truncate it: the
     * refreshed prefix replaces its equivalents and the rows beyond it stay.
     */
    public function testAShorterRefreshDoesNotTruncateThePool(): void
    {
        $this->stubTrending(['One', 'Two', 'Three']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');
        $this->agePool(self::TRENDING_KEY);

        $this->stubTrending(['Fresh One']);
        $this->handleRefresh(new \App\Message\RefreshBrowsePool());

        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertSame(['Fresh One', 'Two', 'Three'], array_column($data['items'], 'title'));
    }

    /** Search pools behave identically, and the queued message carries the query that keys them. */
    public function testStaleSearchPoolIsServedAndQueuesAKeyedRefresh(): void
    {
        $this->stubSearch(['Hit One', 'Hit Two']);
        $this->client->loginUser($this->loadUser('browse-user'));

        $fresh = $this->getJson('/browse/search?q=dune&offset=0&limit=100');
        self::assertSame(['Hit One', 'Hit Two'], array_column($fresh['items'], 'title'));
        self::assertFalse($fresh['stale']);
        $calls = $this->http->getRequestsCount();

        $this->agePool(self::SEARCH_PREFIX . sha1('title|dune'));

        $stale = $this->getJson('/browse/search?q=dune&offset=0&limit=100');
        self::assertSame(['Hit One', 'Hit Two'], array_column($stale['items'], 'title'));
        self::assertTrue($stale['stale']);
        self::assertSame($calls, $this->http->getRequestsCount());
        self::assertSame(1, $this->queuedRefreshes());
        self::assertStringContainsString('dune', $this->queuedRefreshBodies()[0]);

        // And the worker rebuild lands on the same key.
        $this->stubSearch(['Fresh Hit', 'Hit Two']);
        $this->handleRefresh(new \App\Message\RefreshBrowsePool(\App\Message\RefreshBrowsePool::POOL_SEARCH, 'dune', 'title'));
        $after = $this->getJson('/browse/search?q=dune&offset=0&limit=100');
        self::assertSame(['Fresh Hit', 'Hit Two'], array_column($after['items'], 'title'));
        self::assertFalse($after['stale']);
    }

    /**
     * The threshold is the operator's, not a constant: a pool aged past a short setting is stale,
     * and the same age against a long setting is not.
     */
    public function testConfiguredThresholdIsHonoured(): void
    {
        $this->stubTrending(['Warm One']);
        $this->client->loginUser($this->loadUser('browse-user'));
        $this->getJson('/browse/items?offset=0&limit=100');

        // Two hours old: inside a 1-day threshold, so nothing is queued.
        $this->setBrowseRefreshMinutes(1440);
        $this->agePool(self::TRENDING_KEY, 7200);
        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertFalse($data['stale']);
        self::assertSame(0, $this->queuedRefreshes());

        // Same rows, 5-minute threshold: now stale and queued.
        $this->setBrowseRefreshMinutes(5);
        $data = $this->getJson('/browse/items?offset=0&limit=100');
        self::assertTrue($data['stale']);
        self::assertSame(1, $this->queuedRefreshes());
    }

    /** Bounds live in exactly one place, and out-of-range values clamp rather than being rejected. */
    public function testThresholdIsClamped(): void
    {
        $hardcover = new Integration(Integration::KIND_HARDCOVER);
        self::assertSame(Integration::DEFAULT_BROWSE_REFRESH_MINUTES, $hardcover->getBrowseRefreshAfterMinutes());

        self::assertSame(Integration::MIN_BROWSE_REFRESH_MINUTES, $hardcover->setBrowseRefreshAfterMinutes(0)->getBrowseRefreshAfterMinutes());
        self::assertSame(Integration::MAX_BROWSE_REFRESH_MINUTES, $hardcover->setBrowseRefreshAfterMinutes(999999)->getBrowseRefreshAfterMinutes());
        self::assertSame(240, $hardcover->setBrowseRefreshAfterMinutes(240)->getBrowseRefreshAfterMinutes());
    }

    /** Runs the worker half in-process, exactly as the handler does. */
    private function handleRefresh(\App\Message\RefreshBrowsePool $message): void
    {
        (new \App\MessageHandler\RefreshBrowsePoolHandler(self::getContainer()->get(BrowsePoolCache::class)))($message);
    }

    private function setBrowseRefreshMinutes(int $minutes): void
    {
        $hardcover = $this->em->getRepository(Integration::class)->findOneBy(['kind' => Integration::KIND_HARDCOVER]);
        self::assertInstanceOf(Integration::class, $hardcover);
        $hardcover->setBrowseRefreshAfterMinutes($minutes);
        $this->em->flush();
        $this->em->clear();
    }

    private function queuedRefreshes(): int
    {
        return count($this->queuedRefreshBodies());
    }

    /** @return list<string> */
    private function queuedRefreshBodies(): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            "SELECT body FROM messenger_messages WHERE body LIKE '%RefreshBrowsePool%' ORDER BY id",
        );

        return array_map(static fn (mixed $b): string => (string) $b, $rows);
    }

    /** @return array<string, mixed> */
    private function storedPool(string $key): array
    {
        $item = $this->cache->getItem($key);
        self::assertTrue($item->isHit(), "expected a warm pool at {$key}");
        /** @var array<string, mixed> $pool */
        $pool = $item->get();

        return $pool;
    }

    /**
     * Rewrites the cached pool's `fetchedAt` far enough back that it is past the refresh
     * threshold (and clears any failure stamp, so the retry backoff doesn't mask the dispatch).
     * The cache entry itself is untouched — it has no expiry to age.
     */
    private function agePool(string $key, int $seconds = 86400): void
    {
        $item = $this->cache->getItem($key);
        self::assertTrue($item->isHit(), "expected a warm pool at {$key}");
        /** @var array<string, mixed> $pool */
        $pool = $item->get();
        $pool['fetchedAt'] = time() - $seconds;
        $pool['failedAt'] = null;
        $item->set($pool);
        $this->cache->save($item);
        // The in-flight marker outlives a single request, so drop it whenever we deliberately
        // re-stale a pool — otherwise the next assertion measures the previous test step.
        $this->cache->deleteItem('browse.refresh.inflight.' . sha1($key));
        $this->em->getConnection()->executeStatement("DELETE FROM messenger_messages WHERE body LIKE '%RefreshBrowsePool%'");
    }

    /**
     * Trending is two GraphQL calls: `books_trending` ids, then the books themselves. A short
     * page marks the pool exhausted, which keeps the test to exactly one round trip pair.
     *
     * @param list<string> $titles
     */
    private function stubTrending(array $titles): void
    {
        $ids = range(1, max(1, count($titles)));
        $rows = [];
        foreach ($titles as $i => $title) {
            $rows[] = $this->bookRow($ids[$i], $title);
        }
        $this->stubHardcover([
            ['books_trending' => ['ids' => array_slice($ids, 0, count($titles))]],
            ['books' => $rows],
        ]);
    }

    /** @param list<string> $titles */
    private function stubSearch(array $titles): void
    {
        $rows = [];
        foreach ($titles as $i => $title) {
            $rows[] = $this->bookRow($i + 1, $title);
        }
        // searchBooks() resolves ids then hydrates books, same two-call shape as trending.
        $this->stubHardcover([
            ['search' => ['ids' => range(1, max(1, count($titles)))]],
            ['books' => $rows],
        ]);
    }

    /** @return array<string, mixed> */
    private function bookRow(int $id, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => 'slug-' . $id . '-' . strtolower(str_replace(' ', '-', $title)),
            'cached_contributors' => [['author' => ['name' => 'Pool Author']]],
            'cached_image' => ['url' => 'http://covers/' . $id . '.jpg'],
            'editions' => [],
        ];
    }

    /** Every request answered with Hardcover's rate-limit status. */
    private function stubRateLimited(int $responses = 4): void
    {
        $this->http->setResponseFactory(array_fill(
            0,
            $responses,
            new MockResponse('rate limited', ['http_code' => 429]),
        ));
    }

    /** @param list<array<string, mixed>> $payloads GraphQL `data` payloads, in call order. */
    private function stubHardcover(array $payloads): void
    {
        $responses = array_map(
            static fn (array $data): MockResponse => new MockResponse(
                json_encode(['data' => $data], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            $payloads,
        );
        $this->http->setResponseFactory($responses);
    }

    private function seedHardcoverIntegration(): void
    {
        $integration = (new Integration(Integration::KIND_HARDCOVER))
            ->setCredentials(['token' => 'test-token'])
            ->setEnabled(true);
        $this->em->persist($integration);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function seedUser(string $username): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
