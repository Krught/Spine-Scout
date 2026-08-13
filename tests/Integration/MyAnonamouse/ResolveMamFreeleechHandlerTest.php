<?php

declare(strict_types=1);

namespace App\Tests\Integration\MyAnonamouse;

use App\Entity\Book;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\MyAnonamouse\MamAccountStateUpdater;
use App\Integration\MyAnonamouse\MamFreeleechRefresher;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Message\ResolveMamFreeleech;
use App\MessageHandler\ResolveMamFreeleechHandler;
use App\Repository\BookRepository;
use App\Repository\FreeleechItemRepository;
use App\Repository\IntegrationRepository;
use App\Search\Match\MatchScorer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The handler's chaining rule: a scheduled resolution run that leaves work behind, made
 * forward progress and hit no error re-dispatches itself so the backlog drains at Hardcover
 * speed instead of one batch per five-minute tick. Every other outcome — skipped, errored
 * (a 429 belongs to the backoff), nothing left, nothing moved — must break the chain.
 *
 * The refresher is final and the repositories it uses are too, so the real service is driven
 * against the test database with only the HTTP transports doubled (the MamFreeleechRefresherTest
 * convention); the bus is a local spy. No credentials and no MAM traffic are involved.
 */
final class ResolveMamFreeleechHandlerTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;
    private FreeleechItemRepository $items;
    private BookRepository $books;
    private FakeMyAnonamouseSettings $settings;

    protected function setUp(): void
    {
        self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->integrations = $container->get(IntegrationRepository::class);
        $this->items = $container->get(FreeleechItemRepository::class);
        $this->books = $container->get(BookRepository::class);

        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class . ' i WHERE i.kind IN (:kinds)')
            ->setParameter('kinds', [Integration::KIND_MYANONAMOUSE, Integration::KIND_HARDCOVER])
            ->execute();
        $this->integrations->clearSettingsCache();

        $this->settings = new FakeMyAnonamouseSettings($this->config());
    }

    public function testARunThatLeavesWorkBehindChainsTheNextBatch(): void
    {
        $this->configureHardcover();
        $catalog = $this->pendingBacklog(12);

        $dispatched = $this->handle($this->hardcoverBatched($catalog), maxResolutions: 10);

        self::assertCount(1, $dispatched, 'progress plus leftovers chains straight into the next batch');
        self::assertInstanceOf(ResolveMamFreeleech::class, $dispatched[0]);
        self::assertSame(10, $dispatched[0]->maxResolutions, 'the chained run keeps the budget it was given');
        self::assertSame(2, $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING]);
    }

    public function testAFinishedBacklogDoesNotChain(): void
    {
        $this->configureHardcover();
        $catalog = $this->pendingBacklog(3);

        self::assertSame([], $this->handle($this->hardcoverBatched($catalog)), 'nothing left to drain');
        self::assertSame(0, $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING]);

        foreach ($this->items->findByMamTorrentIds([101, 102, 103]) as $mamId => $item) {
            self::assertSame(
                1000 + ($mamId - 100),
                $item->getPopularity(),
                'a resolution captures Hardcover users_count for the trending order',
            );
        }
    }

    public function testASkippedRunDoesNotChain(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(enabled: false));
        $this->configureHardcover();
        $catalog = $this->pendingBacklog(12);

        self::assertSame([], $this->handle($this->hardcoverBatched($catalog), maxResolutions: 10));
    }

    public function testARateLimitedRunDoesNotChainEvenAfterProgress(): void
    {
        $this->configureHardcover();
        // Twenty-five items over batches of ten: the first batch resolves (forward progress),
        // the second earns the 429 that parks the sweep. The backoff owns the retry from here.
        $catalog = $this->pendingBacklog(25);

        $dispatched = $this->handle($this->hardcoverBatched($catalog, rateLimitAfter: 1));

        self::assertSame([], $dispatched, 'a rate limit hands the retry to the backoff, not to the chain');
        self::assertNotSame(
            0,
            $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING],
            'and it really did leave work behind',
        );
        self::assertArrayHasKey('resolveBackoffUntil', $this->settings->accountState);
    }

    public function testARunThatMovedNothingDoesNotChain(): void
    {
        $this->configureHardcover();
        $catalog = $this->pendingBacklog(4);

        // A zero budget leaves every row pending without an error: nothing moved, so chaining
        // would spin forever on the same rows.
        self::assertSame([], $this->handle($this->hardcoverBatched($catalog), maxResolutions: 0));
        self::assertSame(4, $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING]);
    }

    /**
     * Runs the handler once against a spy bus.
     *
     * @return list<object> the messages the handler dispatched
     */
    private function handle(MockHttpClient $hardcover, ?int $maxResolutions = null): array
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        $refresher = new MamFreeleechRefresher(
            new MyAnonamouseClient(new MockHttpClient([]), $this->settings, new NullLogger()),
            $this->settings,
            $this->integrations,
            $this->items,
            $this->books,
            new HardcoverClient($hardcover, new ArrayAdapter(), new NullLogger()),
            new MatchScorer(),
            $this->em,
            new NullLogger(),
            new MamAccountStateUpdater(),
        );

        (new ResolveMamFreeleechHandler($refresher, $bus, new NullLogger()))(new ResolveMamFreeleech($maxResolutions));

        return $bus->dispatched;
    }

    /**
     * $count pending rows plus the Hardcover catalog that answers for them.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function pendingBacklog(int $count): array
    {
        $catalog = [];
        for ($i = 1; $i <= $count; ++$i) {
            $item = new FreeleechItem(100 + $i, sprintf('Iron Gold Volume %02d [ENG / EPUB]', $i), false);
            $item->setResolution(FreeleechItem::RESOLUTION_PENDING);
            $item->setAuthors(['Pierce Brown']);
            $this->em->persist($item);
            $catalog[$i] = [sprintf('Iron Gold Volume %02d', $i), 'Pierce Brown', sprintf('iron-gold-%02d', $i)];
        }
        $this->em->flush();

        return $catalog;
    }

    private function configureHardcover(): void
    {
        $row = new Integration(Integration::KIND_HARDCOVER);
        $row->setEnabled(true);
        $row->setAuthType(Integration::AUTH_API_KEY);
        $row->setCredentials(['token' => 'test-token']);
        $this->em->persist($row);
        $this->em->flush();
        $this->integrations->clearSettingsCache();
    }

    /**
     * Answers every reverse lookup from $catalog; past $rateLimitAfter searches it answers 429
     * the way the live API does.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $catalog
     */
    private function hardcoverBatched(array $catalog, ?int $rateLimitAfter = null): MockHttpClient
    {
        $searches = 0;

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($catalog, $rateLimitAfter, &$searches): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $variables = json_decode($body, true)['variables'] ?? [];

            if (str_contains($body, 'SpineScoutBooksByIds')) {
                $rows = [];
                foreach ($variables['ids'] ?? [] as $id) {
                    $entry = $catalog[(int) $id] ?? null;
                    if ($entry === null) {
                        continue;
                    }
                    $rows[] = [
                        'id'                  => (int) $id,
                        'title'               => $entry[0],
                        'slug'                => $entry[2],
                        'users_count'         => 1000 + (int) $id,
                        'cached_image'        => ['url' => 'https://hardcover.example/' . $entry[2] . '.jpg'],
                        'cached_contributors' => [['author' => ['name' => $entry[1]]]],
                        'editions'            => [['isbn_13' => '9780345539809', 'reading_format_id' => 4]],
                    ];
                }

                return new MockResponse(
                    json_encode(['data' => ['books' => $rows]], JSON_THROW_ON_ERROR),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }
            if ($rateLimitAfter !== null && ++$searches > $rateLimitAfter) {
                return new MockResponse('', ['http_code' => 429]);
            }

            $data = [];
            foreach ($variables as $name => $value) {
                if (!is_string($name) || !str_starts_with($name, 'q') || !is_string($value)) {
                    continue;
                }
                $ids = [];
                foreach ($catalog as $id => $entry) {
                    if (str_contains(strtolower($value), strtolower($entry[0]))) {
                        $ids[] = $id;
                    }
                }
                $data[$name] = ['ids' => $ids];
            }

            return new MockResponse(
                json_encode(['data' => $data], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    private function config(bool $enabled = true): MyAnonamouseConfig
    {
        return new MyAnonamouseConfig(
            enabled: $enabled,
            baseUrl: 'https://www.myanonamouse.net',
            showOnHomepage: true,
            showBrowseShelf: true,
            bookFormatEnabled: true,
            audiobookFormatEnabled: false,
            minSeeders: 0,
            fetchVipFreeleech: true,
            vipFetchLimit: MyAnonamouseConfig::DEFAULT_VIP_FETCH_LIMIT,
            dynamicSeedboxUpdate: false,
            proxyUrl: null,
        );
    }
}
