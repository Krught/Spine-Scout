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
use App\Repository\BookRepository;
use App\Repository\FreeleechItemRepository;
use App\Repository\IntegrationRepository;
use App\Search\Match\MatchScorer;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The freeleech sweep end to end against recorded MAM and Hardcover responses: the
 * skip rules, the interval throttle and its --force bypass, the upsert (which must
 * never re-open a resolved row), the seeder filter, the delete guard that refuses to
 * wipe the table on an ambiguous empty sweep, and the reverse lookup's resolved /
 * unmatched / Hardcover-unconfigured outcomes.
 *
 * Every repository this service touches is final, so the real ones are used against the
 * test database; only the two HTTP transports are doubled (the MyAnonamouseClientTest
 * convention). No credentials are involved and no MAM traffic is possible.
 */
final class MamFreeleechRefresherTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;
    private FreeleechItemRepository $items;
    private BookRepository $books;
    private CoverCache $covers;
    private FakeMyAnonamouseSettings $settings;

    protected function setUp(): void
    {
        self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->integrations = $container->get(IntegrationRepository::class);
        $this->items = $container->get(FreeleechItemRepository::class);
        $this->books = $container->get(BookRepository::class);
        $this->covers = $container->get(CoverCache::class);

        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class . ' i WHERE i.kind IN (:kinds)')
            ->setParameter('kinds', [Integration::KIND_MYANONAMOUSE, Integration::KIND_HARDCOVER])
            ->execute();
        $this->integrations->clearSettingsCache();

        $this->settings = new FakeMyAnonamouseSettings($this->config());
    }

    public function testDisabledIntegrationSkipsWithoutTouchingTheNetwork(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(enabled: false));
        $requests = [];

        $summary = $this->refresher($this->mam([], $requests))->refresh(force: true);

        self::assertTrue($summary['skipped']);
        self::assertSame(0, $summary['fetched']);
        self::assertNull($summary['error']);
        self::assertSame([], $requests);
    }

    public function testMissingCookieSkips(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(), null);

        self::assertTrue($this->refresher($this->mam([], $requests))->refresh(force: true)['skipped']);
    }

    public function testAFreshLastSyncThrottlesTheRunUntilForced(): void
    {
        $row = new Integration(Integration::KIND_MYANONAMOUSE);
        $row->setLastSyncAt(new \DateTimeImmutable('-10 minutes'));
        $row->setSyncIntervalMinutes(360);
        $this->em->persist($row);
        $this->em->flush();
        $this->integrations->clearSettingsCache();

        $requests = [];
        self::assertTrue($this->refresher($this->mam([], $requests))->refresh()['skipped']);
        self::assertSame([], $requests, 'a throttled run makes no MAM request');

        $summary = $this->refresher($this->mam([$this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')])]))
            ->refresh(force: true);

        self::assertFalse($summary['skipped']);
        self::assertSame(1, $summary['fetched']);
        self::assertSame(1, $summary['new']);
    }

    public function testAnElapsedIntervalRunsWithoutForce(): void
    {
        $row = new Integration(Integration::KIND_MYANONAMOUSE);
        $row->setLastSyncAt(new \DateTimeImmutable('-7 hours'));
        $row->setSyncIntervalMinutes(360);
        $this->em->persist($row);
        $this->em->flush();
        $this->integrations->clearSettingsCache();

        $summary = $this->refresher($this->mam([$this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')])]))
            ->refresh();

        self::assertFalse($summary['skipped']);
        self::assertSame(1, $summary['new']);

        $row = $this->integrations->findByKind(Integration::KIND_MYANONAMOUSE);
        self::assertNotNull($row?->getLastSyncAt());
        self::assertGreaterThan((new \DateTimeImmutable('-1 hour'))->getTimestamp(), $row->getLastSyncAt()->getTimestamp());
        self::assertSame($summary['error'], $row->getLastError(), 'the row mirrors the summary error');
    }

    public function testNewRowsAreStoredPendingWithEveryMamFieldMapped(): void
    {
        $summary = $this->refresher($this->mam([$this->searchResponse([
            $this->release(101, 'Red Rising [ENG / EPUB]', seeders: 12, extra: [
                'author_info'   => '{"1":"Pierce Brown"}',
                'narrator_info' => '{"2":"Tim Gerard Reynolds"}',
                'size'          => '1.5 GiB',
                'leechers'      => 3,
                'times_completed' => 40,
                'fl_vip'        => 1,
                'free'          => 1,
                'personal_freeleech' => 1,
                'vip'           => 1,
                'dl'            => 'dlhash101',
                'thumbnail'     => 'https://cdn.example/101.jpg',
                'lang_code'     => 'ENG',
                'filetype'      => 'epub',
                'catname'       => 'Sci-Fi',
                'added'         => '2026-01-02 03:04:05',
            ]),
        ])]))->refresh(force: true);

        self::assertSame(['fetched' => 1, 'new' => 1, 'deleted' => 0], [
            'fetched' => $summary['fetched'],
            'new'     => $summary['new'],
            'deleted' => $summary['deleted'],
        ]);

        $item = $this->items->findByMamTorrentIds([101])[101];
        self::assertSame('Red Rising [ENG / EPUB]', $item->getTitle());
        self::assertSame(['Pierce Brown'], $item->getAuthors());
        self::assertSame(['Tim Gerard Reynolds'], $item->getNarrators());
        self::assertSame((int) round(1.5 * 1024 ** 3), $item->getSizeBytes());
        self::assertSame(12, $item->getSeeders());
        self::assertSame(3, $item->getLeechers());
        self::assertSame(40, $item->getTimesCompleted());
        self::assertTrue($item->isFlVip());
        self::assertTrue($item->isFree(), "MAM's picks are free for everyone, and the shelf is built on this flag");
        self::assertTrue($item->isPersonalFreeleech());
        self::assertTrue($item->isVip());
        self::assertSame('dlhash101', $item->getDlHash());
        self::assertSame('https://cdn.example/101.jpg', $item->getThumbnailUrl());
        self::assertSame('epub', $item->getFiletypes());
        self::assertSame('Sci-Fi', $item->getCatName());
        self::assertSame('2026-01-02', $item->getAddedAt()?->format('Y-m-d'));
        // Hardcover is not configured in this test, so the row stays pending.
        self::assertSame(FreeleechItem::RESOLUTION_PENDING, $item->getResolution());
        self::assertSame(1, $summary['pendingLeft']);
        self::assertStringContainsString('Hardcover is not configured', (string) $summary['error']);
    }

    public function testAnExistingRowKeepsItsResolutionAndBookWhileAvailabilityRefreshes(): void
    {
        $book = $this->books->upsertMetadataBook(
            Book::SOURCE_HARDCOVER,
            'red-rising',
            'Red Rising',
            'Pierce Brown',
            'https://hardcover.app/books/red-rising',
            null,
            [],
            new \DateTimeImmutable(),
        );
        $item = new FreeleechItem(101, 'Red Rising [ENG / EPUB]', false);
        $item->setResolution(FreeleechItem::RESOLUTION_RESOLVED)->setBook($book)->setSeeders(1);
        $this->em->persist($item);
        $this->em->flush();
        $seenBefore = $item->getLastSeenAt();

        $summary = $this->refresher($this->mam([$this->searchResponse([
            $this->release(101, 'Red Rising [ENG / EPUB]', seeders: 99),
        ])]))->refresh(force: true);

        self::assertSame(0, $summary['new']);
        self::assertSame(0, $summary['deleted']);
        $this->em->clear();

        $item = $this->items->findByMamTorrentIds([101])[101];
        self::assertSame(FreeleechItem::RESOLUTION_RESOLVED, $item->getResolution());
        self::assertNotNull($item->getBook());
        self::assertSame(99, $item->getSeeders(), 'availability facts are refreshed');
        self::assertGreaterThanOrEqual($seenBefore->getTimestamp(), $item->getLastSeenAt()->getTimestamp());
    }

    public function testItemsBelowMinSeedersAreNeitherStoredNorKept(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(minSeeders: 5));
        $stale = new FreeleechItem(102, 'Golden Son [ENG / EPUB]', false);
        $this->em->persist($stale);
        $this->em->flush();

        $summary = $this->refresher($this->mam([$this->searchResponse([
            $this->release(101, 'Red Rising [ENG / EPUB]', seeders: 9),
            $this->release(102, 'Golden Son [ENG / EPUB]', seeders: 1),
        ])]))->refresh(force: true);

        self::assertSame(2, $summary['fetched'], 'fetched counts what MAM returned, before filtering');
        self::assertSame(1, $summary['new']);
        self::assertSame(1, $summary['deleted'], 'the under-seeded row is swept out with the rest');
        self::assertSame([101], array_keys($this->items->findByMamTorrentIds([101, 102])));
    }

    public function testAnEmptySweepWithADeadCookieKeepsTheExistingRows(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(audiobooks: true));
        $this->em->persist(new FreeleechItem(101, 'Red Rising [ENG / EPUB]', false));
        $this->em->flush();

        $summary = $this->refresher($this->mam(
            [new MockResponse('', ['http_code' => 403]), new MockResponse('', ['http_code' => 403])],
            $requests,
            userInfo: new MockResponse('', ['http_code' => 403]),
        ))->refresh(force: true);

        self::assertSame(0, $summary['fetched']);
        self::assertSame(0, $summary['deleted']);
        self::assertStringContainsString('kept the existing rows', (string) $summary['error']);
        self::assertSame([101], array_keys($this->items->findByMamTorrentIds([101])));
    }

    public function testAGenuinelyEmptySweepWithALiveSessionSweepsTheTable(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(audiobooks: true));
        $this->em->persist(new FreeleechItem(101, 'Red Rising [ENG / EPUB]', false));
        $this->em->flush();

        $summary = $this->refresher($this->mam([
            $this->searchResponse([]),
            $this->searchResponse([]),
        ]))->refresh(force: true);

        self::assertSame(0, $summary['fetched']);
        self::assertSame(1, $summary['deleted']);
        self::assertNull($summary['error']);
        self::assertSame([], $this->items->findByMamTorrentIds([101]));
    }

    public function testASweepDeletePurgesTheDroppedItemsCachedThumbnail(): void
    {
        $rotatedOut = new FreeleechItem(101, 'Red Rising [ENG / EPUB]', false);
        $rotatedOut->setFree(true)->setThumbnailUrl('https://cdn.example/101.jpg');
        $rotatedOut->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);
        $stillFree = new FreeleechItem(102, 'Golden Son [ENG / EPUB]', false);
        $stillFree->setFree(true)->setThumbnailUrl('https://cdn.example/102.jpg');
        $stillFree->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);
        $this->em->persist($rotatedOut);
        $this->em->persist($stillFree);
        $this->em->flush();

        $droppedFiles = $this->thumbnailCachePaths(101, 'https://cdn.example/101.jpg');
        $keptFiles = $this->thumbnailCachePaths(102, 'https://cdn.example/102.jpg');
        foreach ([...$droppedFiles, ...$keptFiles] as $path) {
            @mkdir(\dirname($path), 0775, true);
            file_put_contents($path, 'cached-bytes');
        }

        try {
            $summary = $this->refresher($this->mam([
                $this->searchResponse([$this->release(102, 'Golden Son [ENG / EPUB]', extra: ['free' => 1])]),
                $this->searchResponse([]),
            ]))->refresh(force: true);

            self::assertSame(1, $summary['deleted']);
            foreach ($droppedFiles as $path) {
                self::assertFileDoesNotExist($path, 'the rotated-out item\'s cached thumbnail is reclaimed');
            }
            foreach ($keptFiles as $path) {
                self::assertFileExists($path, 'the still-free item keeps its cached thumbnail');
            }
        } finally {
            foreach ([...$droppedFiles, ...$keptFiles] as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * The .webp/.meta pair CoverCache keeps for a MAM thumbnail — the hash formula and shard
     * layout pinned here are cache keys, so they must stay stable across releases anyway.
     *
     * @return list<string>
     */
    private function thumbnailCachePaths(int $mamTorrentId, string $url): array
    {
        $hash = sha1('mam:' . $mamTorrentId . ':' . $url);
        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/book-covers/' . substr($hash, 0, 2);

        return [$dir . '/' . $hash . '.webp', $dir . '/' . $hash . '.meta'];
    }

    public function testEveryCategorysRegularSweepRunsBeforeAnyVipCall(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(audiobooks: true));

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(201, 'Sun Eater [ENG / M4B]', mainCat: 13, extra: ['free' => 1, 'fl_vip' => 1])]),
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]', extra: ['free' => 1, 'fl_vip' => 1])]),
            $this->searchResponse([
                $this->release(201, 'Sun Eater [ENG / M4B]', mainCat: 13, extra: ['free' => 1, 'fl_vip' => 1]),
                $this->release(301, 'VIP Only Audio [ENG / M4B]', mainCat: 13, extra: ['fl_vip' => 1, 'vip' => 1]),
            ]),
            $this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['free' => 1, 'fl_vip' => 1]),
                $this->release(401, 'VIP Only Book [ENG / EPUB]', extra: ['fl_vip' => 1, 'vip' => 1]),
            ]),
        ], $requests))->refresh(force: true);

        self::assertSame(
            [['fl', 13], ['fl', 14], ['fl-VIP', 13], ['fl-VIP', 14]],
            $this->searchPhases($requests),
            'both regular sweeps must land before the first VIP call',
        );
        self::assertSame(2, $summary['fetched'], 'fetched stays the regular sweep only');
        self::assertSame(2, $summary['vipFetched']);
        self::assertSame(4, $summary['new']);
        self::assertSame(0, $summary['deleted']);

        $items = $this->items->findByMamTorrentIds([101, 201, 301, 401]);
        self::assertSame([101, 201, 301, 401], $this->sortedIds($items));
        self::assertTrue($items[301]->isFlVip());
        self::assertTrue($items[401]->isFlVip());
        self::assertFalse($items[301]->isFree(), 'the VIP-only additions carry no global free flag');
        self::assertFalse($items[401]->isFree());
        self::assertTrue($items[201]->isFree(), 'the regular sweep\'s picks do');
    }

    public function testVipOnlyAdditionsAreDedupedAgainstTheRegularSet(): void
    {
        $summary = $this->refresher($this->mam([
            $this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]'),
                $this->release(102, 'Golden Son [ENG / EPUB]'),
            ]),
            $this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]'),
                $this->release(102, 'Golden Son [ENG / EPUB]'),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['fl_vip' => 1]),
            ]),
        ]))->refresh(force: true);

        self::assertSame(2, $summary['fetched']);
        self::assertSame(1, $summary['vipFetched'], 'the two rows the regular sweep already carried are not counted twice');
        self::assertSame(3, $summary['new']);
        self::assertSame([101, 102, 103], $this->sortedIds($this->items->findByMamTorrentIds([101, 102, 103])));
    }

    public function testTheVipPhaseIsSkippedWhenTheRegularSweepReturnsNothing(): void
    {
        $summary = $this->refresher($this->mam(
            [new MockResponse('', ['http_code' => 403])],
            $requests,
            userInfo: new MockResponse('', ['http_code' => 403]),
        ))->refresh(force: true);

        self::assertSame([['fl', 14]], $this->searchPhases($requests), 'a failed regular sweep must not be followed by a VIP call');
        self::assertSame(0, $summary['vipFetched']);
        self::assertStringContainsString('VIP freeleech skipped — the regular sweep did not complete.', (string) $summary['error']);
    }

    public function testAnIncompleteVipPhaseKeepsTheExistingVipRowsButNotTheRest(): void
    {
        $vip = new FreeleechItem(900, 'An Older VIP Title [ENG / EPUB]', false);
        $vip->setFlVip(true);
        $this->em->persist($vip);
        $this->em->persist(new FreeleechItem(901, 'An Older Regular Title [ENG / EPUB]', false));
        $this->em->flush();

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')]),
            new MockResponse('', ['http_code' => 403]),
        ]))->refresh(force: true);

        self::assertSame(1, $summary['fetched']);
        self::assertSame(0, $summary['vipFetched']);
        self::assertSame(1, $summary['deleted'], 'only the unconfirmed non-VIP row is swept out');
        self::assertSame([101, 900], $this->sortedIds($this->items->findByMamTorrentIds([101, 900, 901])));
    }

    public function testACompletedVipPhaseSweepsOutTheVipRowsThatRotatedOut(): void
    {
        $vip = new FreeleechItem(900, 'An Older VIP Title [ENG / EPUB]', false);
        $vip->setFlVip(true);
        $this->em->persist($vip);
        $this->em->flush();

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')]),
            $this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]'),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['fl_vip' => 1]),
            ]),
        ]))->refresh(force: true);

        self::assertSame(1, $summary['vipFetched']);
        self::assertSame(1, $summary['deleted']);
        self::assertSame([101, 103], $this->sortedIds($this->items->findByMamTorrentIds([101, 103, 900])));
    }

    public function testTheVipPhaseNeverFiresWhenTheOperatorHasNotOptedIn(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(audiobooks: true, fetchVipFreeleech: false));

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(201, 'Sun Eater [ENG / M4B]', mainCat: 13)]),
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')]),
        ], $requests))->refresh(force: true);

        self::assertSame(
            [['fl', 13], ['fl', 14]],
            $this->searchPhases($requests),
            'not one MAM request may be spent on the VIP pool',
        );
        self::assertSame(2, $summary['fetched']);
        self::assertSame(0, $summary['vipFetched']);
        self::assertStringNotContainsString(
            'VIP freeleech skipped',
            (string) $summary['error'],
            'a phase that is switched off is not a phase that failed',
        );
    }

    public function testTurningTheVipPullOffSweepsOutTheVipOnlyRowsItLeftBehind(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(fetchVipFreeleech: false));

        // What a previous opted-in sweep persisted: a VIP-only row, and a pick that is free
        // for everyone and *also* flagged for VIPs.
        $vipOnly = new FreeleechItem(900, 'A VIP Only Title [ENG / EPUB]', false);
        $vipOnly->setFlVip(true);
        $vipOnly->setFree(false);
        $this->em->persist($vipOnly);
        $globalPick = new FreeleechItem(101, 'Red Rising [ENG / EPUB]', false);
        $globalPick->setFlVip(true);
        $globalPick->setFree(true);
        $this->em->persist($globalPick);
        $this->em->flush();

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]', extra: ['free' => 1, 'fl_vip' => 1])]),
        ]))->refresh(force: true);

        self::assertSame(1, $summary['deleted']);
        self::assertSame(
            [101],
            $this->sortedIds($this->items->findByMamTorrentIds([101, 900])),
            'the VIP-only row goes; the globally free one is part of the regular set and stays',
        );
    }

    public function testTheVipOnlyCleanupStandsEvenWhenTheSweepMayNotDelete(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(fetchVipFreeleech: false));

        $vipOnly = new FreeleechItem(900, 'A VIP Only Title [ENG / EPUB]', false);
        $vipOnly->setFlVip(true);
        $vipOnly->setFree(false);
        $this->em->persist($vipOnly);
        $this->em->persist(new FreeleechItem(901, 'An Older Regular Title [ENG / EPUB]', false));
        $this->em->flush();

        // An empty sweep with no account JSON: the ambiguous-empty guard forbids the sweep
        // delete, but dropping VIP-only rows is a config decision, not a reading of MAM.
        $summary = $this->refresher($this->mam(
            [$this->searchResponse([])],
            userInfo: new MockResponse('', ['http_code' => 403]),
        ))->refresh(force: true);

        self::assertSame(1, $summary['deleted']);
        self::assertSame([901], $this->sortedIds($this->items->findByMamTorrentIds([900, 901])));
    }

    public function testTheVipPullIsCappedToTheNewestVipFetchLimitItems(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(vipFetchLimit: 100));

        $vipRows = [$this->release(101, 'Red Rising [ENG / EPUB]', extra: ['free' => 1])];
        for ($id = 1000; $id < 1150; $id++) {
            $vipRows[] = $this->release($id, 'VIP Row ' . $id . ' [ENG / EPUB]', extra: ['fl_vip' => 1, 'vip' => 1]);
        }

        $summary = $this->refresher($this->mam([
            $this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]', extra: ['free' => 1])]),
            // MAM's own page cap is 100 rows with a much larger `found`, so the walk would keep
            // going; the configured limit is what stops it.
            $this->searchResponse($vipRows),
        ], $requests))->refresh(force: true);

        self::assertSame([['fl', 14], ['fl-VIP', 14]], $this->searchPhases($requests), 'one VIP request, then the cap ends it');
        self::assertSame(1, $summary['fetched']);
        self::assertSame(
            99,
            $summary['vipFetched'],
            'the client hands back exactly 100 rows and one of them is the regular pick phase A already carried',
        );
        self::assertCount(100, $this->items->findByMamTorrentIds(array_merge([101], range(1000, 1149))));
    }

    public function testAMatchingHardcoverResultResolvesTheItemToACatalogBook(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcover('Red Rising', 'Pierce Brown'),
        )->refresh(force: true);

        self::assertSame(1, $summary['resolved']);
        self::assertSame(0, $summary['unmatched']);
        self::assertSame(0, $summary['pendingLeft']);
        self::assertNull($summary['error']);
        self::assertNull($this->integrations->findByKind(Integration::KIND_MYANONAMOUSE)?->getLastError());

        $item = $this->items->findByMamTorrentIds([101])[101];
        self::assertSame(FreeleechItem::RESOLUTION_RESOLVED, $item->getResolution());
        self::assertSame('Red Rising', $item->getBook()?->getTitle());
        self::assertSame(Book::SOURCE_HARDCOVER, $item->getBook()?->getSource());
        self::assertSame('red-rising', $item->getBook()?->getExternalId());
    }

    public function testAWeakHardcoverResultLeavesTheItemUnmatched(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'A Wholly Unrelated Manual [ENG / EPUB]', extra: ['author_info' => '{"1":"Someone Else"}']),
            ])]),
            $this->hardcover('Red Rising', 'Pierce Brown'),
        )->refresh(force: true);

        self::assertSame(0, $summary['resolved']);
        self::assertSame(1, $summary['unmatched']);
        self::assertSame(0, $summary['pendingLeft']);

        $item = $this->items->findByMamTorrentIds([101])[101];
        self::assertSame(FreeleechItem::RESOLUTION_UNMATCHED, $item->getResolution());
        self::assertNull($item->getBook());
    }

    public function testAHardcoverRateLimitStopsTheBatchAndDefersTheRest(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcover('Red Rising', 'Pierce Brown', rateLimitAfter: 1),
        )->refresh(force: true);

        self::assertSame(1, $summary['resolved']);
        self::assertSame(2, $summary['pendingLeft'], 'the rate-limited item and its successors stay pending');
        self::assertStringContainsString('Hardcover rate limit hit — 2 item(s) deferred', (string) $summary['error']);

        foreach ([102, 103] as $id) {
            self::assertSame(
                FreeleechItem::RESOLUTION_PENDING,
                $this->items->findByMamTorrentIds([$id])[$id]->getResolution(),
            );
        }
    }

    public function testMaxResolutionsCapsHowManyItemsOneRunLooksUp(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcover('Red Rising', 'Pierce Brown'),
        )->refresh(force: true, maxResolutions: 1);

        self::assertSame(1, $summary['resolved'] + $summary['unmatched'], 'only the budgeted lookup ran');
        self::assertSame(2, $summary['pendingLeft']);
        self::assertNull($summary['error'], 'a budgeted run is not an error');
    }

    public function testTheAccountSnapshotIsMergedRatherThanReplaced(): void
    {
        $this->settings->accountState = ['lastIpUpdateAt' => '2026-01-01T00:00:00+00:00', 'keepMe' => 'yes'];

        $this->refresher($this->mam([$this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')])]))
            ->refresh(force: true);

        self::assertSame('yes', $this->settings->accountState['keepMe']);
        self::assertSame('bookmouse', $this->settings->accountState['username']);
        self::assertTrue($this->settings->accountState['isVip']);
        self::assertArrayHasKey('checkedAt', $this->settings->accountState);
        self::assertArrayNotHasKey('lastIpUpdateOk', $this->settings->accountState, 'the keepalive is off by default');
    }

    public function testTheDynamicSeedboxKeepaliveRunsOnceEveryThreeHours(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(dynamicSeedbox: true));
        $this->settings->accountState = ['lastIpUpdateAt' => (new \DateTimeImmutable('-10 minutes'))->format(\DateTimeInterface::ATOM)];

        $this->refresher($this->mam([$this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')])]))
            ->refresh(force: true);
        self::assertArrayNotHasKey('lastIpUpdateOk', $this->settings->accountState, 'still inside the 3-hour window');

        $this->settings->accountState['lastIpUpdateAt'] = (new \DateTimeImmutable('-4 hours'))->format(\DateTimeInterface::ATOM);
        $this->refresher($this->mam(
            [$this->searchResponse([$this->release(101, 'Red Rising [ENG / EPUB]')])],
            $requests,
            seedbox: new MockResponse('{"Success":true}', ['response_headers' => ['content-type' => 'application/json']]),
        ))->refresh(force: true);

        self::assertTrue($this->settings->accountState['lastIpUpdateOk']);
        self::assertNotSame('', (string) $this->settings->accountState['lastIpUpdateAt']);
    }

    public function testABookTheCatalogAlreadyHasResolvesLocallyWithoutOneHardcoverRequest(): void
    {
        $this->configureHardcover();
        $this->catalogBook('Red Rising', 'Pierce Brown', 'red-rising');

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcoverBatched([], requests: $hardcoverRequests),
        )->refresh(force: true);

        self::assertSame([], $hardcoverRequests, 'a locally matched item costs no HTTP');
        self::assertSame(1, $summary['localResolved']);
        self::assertSame(1, $summary['resolved']);
        self::assertSame(0, $summary['unmatched']);
        self::assertSame(0, $summary['pendingLeft']);
        self::assertNull($summary['error']);

        $item = $this->items->findByMamTorrentIds([101])[101];
        self::assertSame(FreeleechItem::RESOLUTION_RESOLVED, $item->getResolution());
        self::assertSame('red-rising', $item->getBook()?->getExternalId());
    }

    public function testLocalMatchesStillResolveWhileHardcoverIsUnconfigured(): void
    {
        $this->catalogBook('Red Rising', 'Pierce Brown', 'red-rising');

        $summary = $this->refresher($this->mam([$this->searchResponse([
            $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
        ])]))->refresh(force: true);

        self::assertSame(1, $summary['localResolved']);
        self::assertSame(1, $summary['pendingLeft']);
        self::assertStringContainsString('Hardcover is not configured — 1 item(s) left pending.', (string) $summary['error']);

        $items = $this->items->findByMamTorrentIds([101, 102]);
        self::assertSame(FreeleechItem::RESOLUTION_RESOLVED, $items[101]->getResolution());
        self::assertSame(FreeleechItem::RESOLUTION_PENDING, $items[102]->getResolution());
    }

    public function testOneAliasedRequestCarriesExactlyTheItemsTheCatalogCouldNotMatch(): void
    {
        $this->configureHardcover();
        $this->catalogBook('Red Rising', 'Pierce Brown', 'red-rising');

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcoverBatched([
                2 => ['Golden Son', 'Pierce Brown', 'golden-son'],
                3 => ['Morning Star', 'Pierce Brown', 'morning-star'],
            ], requests: $hardcoverRequests),
        )->refresh(force: true);

        $batches = $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch(');
        self::assertCount(1, $batches, 'two lookups ride one request');
        self::assertSame(['per', 'q0', 'q1'], array_keys($batches[0]));
        self::assertSame('Golden Son Pierce Brown', $batches[0]['q0']);
        self::assertSame('Morning Star Pierce Brown', $batches[0]['q1']);
        self::assertSame([], $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearch('), 'nothing falls back to per-item');

        self::assertSame(1, $summary['localResolved']);
        self::assertSame(3, $summary['resolved']);
        self::assertSame(0, $summary['pendingLeft']);
    }

    public function testABatchedResponseIsDemuxedBackToTheRightItems(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(104, 'A Wholly Unrelated Manual [ENG / EPUB]', extra: ['author_info' => '{"1":"Someone Else"}']),
            ])]),
            $this->hardcoverBatched([2 => ['Golden Son', 'Pierce Brown', 'golden-son']]),
        )->refresh(force: true);

        self::assertSame(1, $summary['resolved']);
        self::assertSame(1, $summary['unmatched']);

        $items = $this->items->findByMamTorrentIds([102, 104]);
        self::assertSame(FreeleechItem::RESOLUTION_RESOLVED, $items[102]->getResolution());
        self::assertSame('golden-son', $items[102]->getBook()?->getExternalId());
        self::assertSame(FreeleechItem::RESOLUTION_UNMATCHED, $items[104]->getResolution());
        self::assertNull($items[104]->getBook());
    }

    public function testAnAliasThatComesBackWithoutAnIdsKeyIsJustAnItemWithNoHits(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(104, 'A Wholly Unrelated Manual [ENG / EPUB]', extra: ['author_info' => '{"1":"Someone Else"}']),
            ])]),
            $this->hardcoverIdlessAlias('Golden Son', 'Pierce Brown', 'golden-son', $hardcoverRequests),
        )->refresh(force: true);

        self::assertCount(1, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch('));
        self::assertSame([], $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearch('), 'a hitless alias is no reason to fall back');
        self::assertSame(1, $summary['resolved']);
        self::assertSame(1, $summary['unmatched']);
        self::assertNull($summary['error']);

        $items = $this->items->findByMamTorrentIds([102, 104]);
        self::assertSame('golden-son', $items[102]->getBook()?->getExternalId());
        self::assertSame(FreeleechItem::RESOLUTION_UNMATCHED, $items[104]->getResolution());
    }

    public function testARateLimitedBatchDefersEveryItemItCarried(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(103, 'Morning Star [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcoverBatched([1 => ['Red Rising', 'Pierce Brown', 'red-rising']], rateLimitAfter: 0, requests: $hardcoverRequests),
        )->refresh(force: true);

        self::assertCount(1, $hardcoverRequests, 'the 429 stops the sweep at the first batch');
        self::assertSame(0, $summary['resolved']);
        self::assertSame(3, $summary['pendingLeft']);
        self::assertStringContainsString('Hardcover rate limit hit — 3 item(s) deferred', (string) $summary['error']);
    }

    public function testABatchRejectedForAnythingButRateLimitingFallsBackToPerItemLookups(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
                $this->release(102, 'Golden Son [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcover('Red Rising', 'Pierce Brown', requests: $hardcoverRequests),
        )->refresh(force: true);

        self::assertCount(1, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch('));
        self::assertCount(2, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearch('), 'both items are retried one by one');
        self::assertSame(1, $summary['resolved']);
        self::assertSame(1, $summary['unmatched']);
        self::assertSame(0, $summary['pendingLeft']);
        self::assertNull($summary['error']);
    }

    public function testTheResolutionBudgetCountsItemsRatherThanBatchRequests(): void
    {
        $this->configureHardcover();
        $releases = [];
        $catalog = [];
        for ($i = 1; $i <= 12; ++$i) {
            $releases[] = $this->release(100 + $i, sprintf('Iron Gold Volume %02d [ENG / EPUB]', $i), extra: ['author_info' => '{"1":"Pierce Brown"}']);
            $catalog[$i] = [sprintf('Iron Gold Volume %02d', $i), 'Pierce Brown', sprintf('iron-gold-%02d', $i)];
        }

        $summary = $this->refresher(
            $this->mam([$this->searchResponse($releases)]),
            $this->hardcoverBatched($catalog, requests: $hardcoverRequests),
        )->refresh(force: true, maxResolutions: 5);

        self::assertCount(1, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch('), 'five items are one request');
        self::assertSame(5, $summary['resolved'] + $summary['unmatched'], 'the budget caps items, not requests');
        self::assertSame(7, $summary['pendingLeft']);
        self::assertNull($summary['error']);
    }

    public function testResolvePendingDrainsTheBacklogWithoutOneMamRequestOrAWorkingCookie(): void
    {
        $this->settings = new FakeMyAnonamouseSettings($this->config(), null);
        $this->configureHardcover();
        $this->catalogBook('Red Rising', 'Pierce Brown', 'red-rising');
        $this->pendingItem(101, 'Red Rising [ENG / EPUB]', 'Pierce Brown');
        $this->pendingItem(102, 'Golden Son [ENG / EPUB]', 'Pierce Brown');
        $this->em->flush();

        $summary = $this->refresher(
            $this->mam([], $mamRequests),
            $this->hardcoverBatched([2 => ['Golden Son', 'Pierce Brown', 'golden-son']], requests: $hardcoverRequests),
        )->resolvePending();

        self::assertSame([], $mamRequests, 'resolution talks to the catalog and Hardcover only');
        self::assertFalse($summary['skipped']);
        self::assertSame(1, $summary['localResolved']);
        self::assertSame(2, $summary['resolved']);
        self::assertSame(0, $summary['unmatched']);
        self::assertSame(0, $summary['deferred']);
        self::assertSame(0, $summary['pendingLeft']);
        self::assertNull($summary['error']);
        self::assertCount(1, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch('), 'the catalog miss rides one batch');

        $this->em->clear();
        $items = $this->items->findByMamTorrentIds([101, 102]);
        self::assertSame('red-rising', $items[101]->getBook()?->getExternalId(), 'the run owns its flush');
        self::assertSame('golden-son', $items[102]->getBook()?->getExternalId());
    }

    /**
     * The ebook and the audiobook of one title are two MAM torrents but one Hardcover book. The
     * second one must land on the book the first created rather than insert a duplicate the
     * closing flush would die on (books_source_external_uniq).
     */
    public function testTwoEditionsOfOneTitleResolveToASingleCatalogBook(): void
    {
        $this->configureHardcover();
        $this->pendingItem(101, 'The Secrets We Hide [ENG / EPUB]', 'Jane Doe');
        $this->pendingItem(102, 'The Secrets We Hide [ENG / M4B]', 'Jane Doe', audiobook: true);
        $this->em->flush();

        $summary = $this->refresher(
            $this->mam([]),
            $this->hardcoverBatched([1 => ['The Secrets We Hide', 'Jane Doe', 'the-secrets-we-hide-2026']]),
        )->resolvePending();

        self::assertSame(2, $summary['resolved']);
        self::assertSame(0, $summary['unmatched']);
        self::assertSame(0, $summary['pendingLeft']);
        self::assertNull($summary['error']);

        $this->em->clear();
        $items = $this->items->findByMamTorrentIds([101, 102]);
        $book = $items[101]->getBook();
        self::assertNotNull($book);
        self::assertSame($book->getId(), $items[102]->getBook()?->getId(), 'both editions share one catalog row');
        self::assertCount(
            1,
            $this->books->findBy(['source' => Book::SOURCE_HARDCOVER, 'externalId' => 'the-secrets-we-hide-2026']),
            'the slug is upserted exactly once',
        );
        self::assertTrue($book->isAudiobookAvailable(), 'the audiobook edition still flips availability on');
    }

    public function testResolvePendingSkipsWhileTheIntegrationIsDisabled(): void
    {
        $this->configureHardcover();
        $this->catalogBook('Red Rising', 'Pierce Brown', 'red-rising');
        $this->pendingItem(101, 'Red Rising [ENG / EPUB]', 'Pierce Brown');
        $this->em->flush();
        $this->settings = new FakeMyAnonamouseSettings($this->config(enabled: false));

        $summary = $this->refresher($this->mam([], $mamRequests), $this->hardcoverBatched([], requests: $hardcoverRequests))
            ->resolvePending();

        self::assertTrue($summary['skipped']);
        self::assertSame([], $mamRequests);
        self::assertSame([], $hardcoverRequests);
        self::assertSame(FreeleechItem::RESOLUTION_PENDING, $this->items->findByMamTorrentIds([101])[101]->getResolution());
    }

    public function testAnEmptyBacklogSkipsWithoutOneRequest(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher($this->mam([], $mamRequests), $this->hardcoverBatched([], requests: $hardcoverRequests))
            ->resolvePending();

        self::assertTrue($summary['skipped']);
        self::assertSame([], $mamRequests);
        self::assertSame([], $hardcoverRequests);
    }

    public function testAHardcoverRateLimitParksTheNextResolutionRunUntilTheBackoffElapses(): void
    {
        $this->configureHardcover();
        $this->pendingItem(101, 'Red Rising [ENG / EPUB]', 'Pierce Brown');
        $this->pendingItem(102, 'Golden Son [ENG / EPUB]', 'Pierce Brown');
        $this->em->flush();

        $first = $this->refresher($this->mam([]), $this->hardcoverBatched([], rateLimitAfter: 0))->resolvePending();

        self::assertFalse($first['skipped']);
        self::assertSame(2, $first['deferred']);
        self::assertSame(2, $first['pendingLeft']);
        $until = $this->settings->accountState['resolveBackoffUntil'] ?? null;
        self::assertIsString($until);
        self::assertGreaterThan(
            (new \DateTimeImmutable('+14 minutes'))->getTimestamp(),
            (new \DateTimeImmutable($until))->getTimestamp(),
        );

        $second = $this->refresher($this->mam([]), $this->hardcoverBatched([], requests: $hardcoverRequests))->resolvePending();

        self::assertTrue($second['skipped'], 'the tick inside the window does not run');
        self::assertSame([], $hardcoverRequests, 'and costs no Hardcover request');

        $this->settings->accountState['resolveBackoffUntil'] = (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM);
        $third = $this->refresher(
            $this->mam([]),
            $this->hardcoverBatched([1 => ['Red Rising', 'Pierce Brown', 'red-rising']]),
        )->resolvePending();

        self::assertFalse($third['skipped'], 'an elapsed backoff is ignored');
        self::assertSame(1, $third['resolved']);
        self::assertSame(1, $third['unmatched']);
    }

    /**
     * RESOLVE_CRON_BATCH is now the whole pending batch (the handler chains follow-up runs
     * instead of capping one), so the budget is exercised with an explicit smaller cap.
     */
    public function testAnExplicitResolutionBudgetCapsHowManyItemsOneRunLooksUp(): void
    {
        $this->configureHardcover();
        $catalog = [];
        for ($i = 1; $i <= 15; ++$i) {
            $this->pendingItem(100 + $i, sprintf('Iron Gold Volume %02d [ENG / EPUB]', $i), 'Pierce Brown');
            $catalog[$i] = [sprintf('Iron Gold Volume %02d', $i), 'Pierce Brown', sprintf('iron-gold-%02d', $i)];
        }
        $this->em->flush();

        $summary = $this->refresher($this->mam([]), $this->hardcoverBatched($catalog, requests: $hardcoverRequests))
            ->resolvePending(10);

        self::assertCount(1, $this->graphqlVariables($hardcoverRequests, 'SpineScoutSearchBatch('), 'ten items ride one batch');
        self::assertSame(10, $summary['resolved'] + $summary['unmatched'], 'the run stops at its budget');
        self::assertSame(5, $summary['pendingLeft']);
        self::assertNull($summary['error']);
        self::assertSame(200, MamFreeleechRefresher::RESOLVE_CRON_BATCH, 'the scheduled run takes the whole pending batch');
    }

    public function testRefreshStillReturnsItsFullSweepSummary(): void
    {
        $this->configureHardcover();

        $summary = $this->refresher(
            $this->mam([$this->searchResponse([
                $this->release(101, 'Red Rising [ENG / EPUB]', extra: ['author_info' => '{"1":"Pierce Brown"}']),
            ])]),
            $this->hardcoverBatched([1 => ['Red Rising', 'Pierce Brown', 'red-rising']]),
        )->refresh(force: true);

        self::assertSame(
            ['skipped', 'fetched', 'vipFetched', 'new', 'deleted', 'resolved', 'localResolved', 'unmatched', 'pendingLeft', 'error'],
            array_keys($summary),
        );
        self::assertSame(
            ['skipped' => false, 'fetched' => 1, 'vipFetched' => 0, 'new' => 1, 'deleted' => 0, 'resolved' => 1, 'localResolved' => 0, 'unmatched' => 0, 'pendingLeft' => 0, 'error' => null],
            $summary,
        );
    }

    // -- harness -----------------------------------------------------------

    private function refresher(MockHttpClient $mam, ?MockHttpClient $hardcover = null): MamFreeleechRefresher
    {
        return new MamFreeleechRefresher(
            new MyAnonamouseClient($mam, $this->settings, new NullLogger()),
            $this->settings,
            $this->integrations,
            $this->items,
            $this->books,
            new HardcoverClient($hardcover ?? new MockHttpClient([]), new ArrayAdapter(), new NullLogger()),
            new MatchScorer(),
            $this->em,
            new NullLogger(),
            new MamAccountStateUpdater(),
            $this->covers,
        );
    }

    private function catalogBook(string $title, string $author, string $externalId): Book
    {
        $book = $this->books->upsertMetadataBook(
            Book::SOURCE_HARDCOVER,
            $externalId,
            $title,
            $author,
            'https://hardcover.app/books/' . $externalId,
            null,
            [],
            new \DateTimeImmutable(),
        );
        $book->setDownloaded(true);
        $this->em->flush();

        return $book;
    }

    /** One row in the state the resolution sweep reads: pending, with the MAM strings it searches on. */
    private function pendingItem(int $mamTorrentId, string $title, ?string $author = null, bool $audiobook = false): FreeleechItem
    {
        $item = new FreeleechItem($mamTorrentId, $title, $audiobook);
        $item->setResolution(FreeleechItem::RESOLUTION_PENDING);
        if ($author !== null) {
            $item->setAuthors([$author]);
        }
        $this->em->persist($item);

        return $item;
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
     * Routes by endpoint so a test only has to line up the search pages it cares about:
     * jsonLoad.php and the seedbox keepalive answer from their own slots.
     *
     * @param list<MockResponse>                                          $searchPages
     * @param list<array{method: string, url: string, body: string}>|null $requests
     */
    private function mam(array $searchPages, ?array &$requests = null, ?MockResponse $userInfo = null, ?MockResponse $seedbox = null): MockHttpClient
    {
        $requests = [];
        $index = 0;
        $userInfo ??= new MockResponse(
            '{"username":"bookmouse","classname":"Elite VIP","ratio":"12.34"}',
            ['response_headers' => ['content-type' => 'application/json']],
        );
        $seedbox ??= new MockResponse('{"Success":false}', ['response_headers' => ['content-type' => 'application/json']]);

        return new MockHttpClient(
            static function (string $method, string $url, array $options) use ($searchPages, &$index, &$requests, $userInfo, $seedbox): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'body' => is_string($options['body'] ?? null) ? $options['body'] : ''];

                if (str_contains($url, 'jsonLoad.php')) {
                    return $userInfo;
                }
                if (str_contains($url, 'dynamicSeedbox.php')) {
                    return $seedbox;
                }

                return $searchPages[$index++] ?? new MockResponse('', ['http_code' => 500]);
            },
        );
    }

    /**
     * One Hardcover book, answered for every search the sweep runs. With $rateLimitAfter set,
     * every search past the Nth answers HTTP 429 the way the live API did.
     *
     * @param list<string>|null $requests
     */
    private function hardcover(string $title, string $author, ?int $rateLimitAfter = null, ?array &$requests = null): MockHttpClient
    {
        $requests = [];
        $book = json_encode([
            'data' => ['books' => [[
                'id'                  => 1,
                'title'               => $title,
                'slug'                => 'red-rising',
                'cached_image'        => ['url' => 'https://hardcover.example/red-rising.jpg'],
                'cached_contributors' => [['author' => ['name' => $author]]],
                'editions'            => [['isbn_13' => '9780345539809', 'reading_format_id' => 4]],
            ]]],
        ], JSON_THROW_ON_ERROR);

        $searches = 0;

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($book, $rateLimitAfter, &$searches, &$requests): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $requests[] = $body;
            // This double predates field aliases and answers them the way an API that does not
            // take them would, so every test built on it exercises the per-item fallback.
            if (str_contains($body, 'SpineScoutSearchBatch')) {
                return new MockResponse(
                    '{"errors":[{"message":"aliased queries are not supported"}]}',
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }
            if (str_contains($body, 'SpineScoutBooksByIds')) {
                return new MockResponse($book, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if ($rateLimitAfter !== null && ++$searches > $rateLimitAfter) {
                return new MockResponse('', ['http_code' => 429]);
            }

            return new MockResponse('{"data":{"search":{"ids":[1]}}}', ['response_headers' => ['content-type' => 'application/json']]);
        });
    }

    /**
     * A Hardcover transport that speaks the aliased batch protocol: each `q<N>` variable is
     * matched against $catalog by title substring, so one request demuxes into per-alias hits.
     * $catalog is keyed by Hardcover book id and holds [title, author, slug].
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $catalog
     * @param list<string>|null                                  $requests
     */
    private function hardcoverBatched(array $catalog, ?int $rateLimitAfter = null, ?array &$requests = null): MockHttpClient
    {
        $requests = [];
        $searches = 0;

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($catalog, $rateLimitAfter, &$searches, &$requests): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $requests[] = $body;
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

    /**
     * The live shape behind "missing search.ids": the first alias hits book 1, every later one
     * comes back as a bare `{}` with no `ids` key at all.
     *
     * @param list<string>|null $requests
     */
    private function hardcoverIdlessAlias(string $title, string $author, string $slug, ?array &$requests = null): MockHttpClient
    {
        $requests = [];

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($title, $author, $slug, &$requests): MockResponse {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $requests[] = $body;
            $variables = json_decode($body, true)['variables'] ?? [];

            if (str_contains($body, 'SpineScoutBooksByIds')) {
                return new MockResponse(
                    json_encode(['data' => ['books' => [[
                        'id'                  => 1,
                        'title'               => $title,
                        'slug'                => $slug,
                        'cached_image'        => ['url' => 'https://hardcover.example/' . $slug . '.jpg'],
                        'cached_contributors' => [['author' => ['name' => $author]]],
                        'editions'            => [['isbn_13' => '9780345539809', 'reading_format_id' => 4]],
                    ]]]], JSON_THROW_ON_ERROR),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }

            $data = [];
            foreach ($variables as $name => $value) {
                if (!is_string($name) || !str_starts_with($name, 'q') || !is_string($value)) {
                    continue;
                }
                $data[$name] = $data === [] ? ['ids' => [1]] : new \stdClass();
            }

            return new MockResponse(
                json_encode(['data' => $data], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    /**
     * @param list<string> $requests
     *
     * @return list<array<string, mixed>> the variables of every request carrying $operation
     */
    private function graphqlVariables(array $requests, string $operation): array
    {
        $out = [];
        foreach ($requests as $body) {
            if (str_contains($body, $operation)) {
                $out[] = json_decode($body, true)['variables'] ?? [];
            }
        }

        return $out;
    }

    /**
     * @param array<int, FreeleechItem> $items
     *
     * @return list<int>
     */
    private function sortedIds(array $items): array
    {
        $ids = array_keys($items);
        sort($ids);

        return $ids;
    }

    /**
     * The search calls in the order they were made, as [searchType, main_cat] pairs.
     *
     * @param list<array{method: string, url: string, body: string}> $requests
     *
     * @return list<array{0: string, 1: int}>
     */
    private function searchPhases(array $requests): array
    {
        $out = [];
        foreach ($requests as $request) {
            if (!str_contains($request['url'], 'loadSearchJSONbasic.php')) {
                continue;
            }
            $body = urldecode($request['body']);
            preg_match('/tor\[searchType\]=([^&]*)/', $body, $type);
            preg_match('/tor\[main_cat\]\[0\]=(\d+)/', $body, $cat);

            $out[] = [$type[1] ?? '', (int) ($cat[1] ?? 0)];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function searchResponse(array $rows): MockResponse
    {
        return new MockResponse(
            json_encode(['data' => $rows, 'found' => \count($rows), 'perpage' => 100, 'start' => 0], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function release(int $id, string $title, int $seeders = 10, int $mainCat = 14, array $extra = []): array
    {
        return $extra + [
            'id'       => $id,
            'title'    => $title,
            'main_cat' => $mainCat,
            'seeders'  => $seeders,
            'size'     => '1.2 MiB',
        ];
    }

    private function config(
        bool $enabled = true,
        int $minSeeders = 0,
        bool $audiobooks = false,
        bool $dynamicSeedbox = false,
        // The VIP phase is opt-in in production; these tests default it on because most of
        // them are about that phase, and the off-path has its own cases.
        bool $fetchVipFreeleech = true,
        int $vipFetchLimit = MyAnonamouseConfig::DEFAULT_VIP_FETCH_LIMIT,
    ): MyAnonamouseConfig {
        return new MyAnonamouseConfig(
            enabled: $enabled,
            baseUrl: 'https://www.myanonamouse.net',
            showOnHomepage: true,
            showBrowseShelf: true,
            bookFormatEnabled: true,
            audiobookFormatEnabled: $audiobooks,
            minSeeders: $minSeeders,
            fetchVipFreeleech: $fetchVipFreeleech,
            vipFetchLimit: $vipFetchLimit,
            dynamicSeedboxUpdate: $dynamicSeedbox,
            proxyUrl: null,
        );
    }
}
