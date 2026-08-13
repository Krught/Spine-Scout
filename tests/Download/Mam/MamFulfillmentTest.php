<?php

declare(strict_types=1);

namespace App\Tests\Download\Mam;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FulfillmentLog;
use App\Download\Mam\MamFulfillment;
use App\Entity\Book;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\TorrentMatchScorer;
use App\Tests\Integration\MyAnonamouse\FakeMyAnonamouseSettings;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for the MAM grab pipeline: the wedge-spend decision and ordering
 * (spend before add, never on already-free releases, refusal is non-fatal), the
 * .torrent fetch guard, the job stamping contract (source=mam + clientRef +
 * DOWNLOADING), and tryFulfill's blocklist filter. The MAM transport is the real
 * MyAnonamouseClient over a mocked HTTP layer, so cookie/JSON handling is
 * exercised too; only the download client and repositories are faked.
 */
final class MamFulfillmentTest extends TestCase
{
    private const BASE_URL = 'https://mam.test';
    private const TORRENT_BYTES = 'd8:announce26:https://mam.test/announce4:infod4:name3:fooee';

    /** Ordered record of what the pipeline touched: 'search', 'wedge', 'torrent-file', 'add'. */
    private array $events = [];

    /** Requested MAM URLs, for asserting on wedge parameters. */
    private array $mamUrls = [];

    protected function setUp(): void
    {
        $this->events = [];
        $this->mamUrls = [];
    }

    public function testGrabSpendsWedgeBeforeAddWhenRequestedAndNotFree(): void
    {
        $settings = $this->settings();
        $client = $this->downloadClient();
        $job = $this->job();

        $ok = $this->fulfillment($settings, $client)->grab($job, $this->candidate(), 'Red Rising', true);

        self::assertTrue($ok);
        self::assertSame(['wedge', 'torrent-file', 'add'], $this->events, 'the wedge must be spent before the torrent is fetched and added');
        self::assertStringContainsString('spendtype=personalFL&torrentid=123456', implode("\n", $this->mamUrls));

        self::assertSame('mam', $job->getSource());
        self::assertSame('123456', $job->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $job->getProtocol());
        self::assertSame('epub', $job->getFormat());
        self::assertSame(123_456_789, $job->getSizeBytes());
        self::assertSame(self::BASE_URL . '/tor/download.php/dl-hash-abc', $job->getDownloadUrl());
        self::assertSame([self::BASE_URL . '/tor/download.php/dl-hash-abc'], $job->getCandidateLinks());
        self::assertSame('added-hash', $job->getClientRef());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());

        self::assertCount(1, $client->added);
        [$url, $name, $options] = $client->added[0];
        self::assertSame(self::BASE_URL . '/tor/download.php/dl-hash-abc', $url);
        self::assertSame('Red Rising', $name);
        self::assertSame(self::TORRENT_BYTES, $options['fileContents'] ?? null, 'the raw .torrent bytes must reach the download client');
    }

    public function testGrabSkipsWedgeWhenReleaseIsAlreadyFree(): void
    {
        $ok = $this->fulfillment($this->settings(), $this->downloadClient())
            ->grab($this->job(), $this->candidate(free: true), 'Red Rising', true);

        self::assertTrue($ok);
        self::assertSame(['torrent-file', 'add'], $this->events, 'no wedge on a sitewide-freeleech release');
    }

    public function testGrabSkipsWedgeForVipUserOnVipFreeleech(): void
    {
        $settings = $this->settings();
        $settings->accountState = ['isVip' => true];

        $ok = $this->fulfillment($settings, $this->downloadClient())
            ->grab($this->job(), $this->candidate(flVip: true), 'Red Rising', true);

        self::assertTrue($ok);
        self::assertSame(['torrent-file', 'add'], $this->events, 'VIP freeleech is already free for a VIP account');
    }

    public function testGrabSpendsWedgeOnVipFreeleechWhenUserIsNotVip(): void
    {
        // The same flVip release costs a non-VIP account ratio, so the wedge applies.
        $ok = $this->fulfillment($this->settings(), $this->downloadClient())
            ->grab($this->job(), $this->candidate(flVip: true), 'Red Rising', true);

        self::assertTrue($ok);
        self::assertSame(['wedge', 'torrent-file', 'add'], $this->events);
    }

    public function testGrabSkipsWedgeWhenNotRequested(): void
    {
        $ok = $this->fulfillment($this->settings(), $this->downloadClient())
            ->grab($this->job(), $this->candidate(), 'Red Rising', false);

        self::assertTrue($ok);
        self::assertSame(['torrent-file', 'add'], $this->events);
    }

    public function testRefusedWedgeSpendStillGrabs(): void
    {
        $job = $this->job();

        $ok = $this->fulfillment($this->settings(), $this->downloadClient(), wedgeSucceeds: false)
            ->grab($job, $this->candidate(), 'Red Rising', true);

        self::assertTrue($ok);
        self::assertSame(['wedge', 'torrent-file', 'add'], $this->events, 'a refused wedge is non-fatal — the grab proceeds at ratio cost');
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
    }

    public function testGrabThrowsWhenMamServesNoTorrentFile(): void
    {
        $job = $this->job();
        $fulfillment = $this->fulfillment($this->settings(), $this->downloadClient(), torrentFileOk: false);

        try {
            $fulfillment->grab($job, $this->candidate(), 'Red Rising', false);
            self::fail('expected the missing .torrent file to throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('did not serve the .torrent file', $e->getMessage());
        }

        // Nothing was added and the job never flipped to DOWNLOADING.
        self::assertNotContains('add', $this->events);
        self::assertNull($job->getClientRef());
        self::assertNotSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
    }

    public function testGrabReturnsFalseWhenNoTorrentClientIsConfigured(): void
    {
        $job = $this->job();

        $ok = $this->fulfillment($this->settings(), $this->downloadClient(configured: false))
            ->grab($job, $this->candidate(), 'Red Rising', true);

        self::assertFalse($ok);
        self::assertSame([], $this->events, 'an unconfigured client must not spend a wedge');
        self::assertSame('seed', $job->getSource());
    }

    public function testTryFulfillSkipsBlockedReleasesAndGrabsTheNextBest(): void
    {
        $client = $this->downloadClient();
        $job = $this->job();

        $blocked = $this->createStub(BlockedReleaseRepository::class);
        $blocked->method('blockedKeysForBook')->willReturn(['mam|111' => true]);

        $ok = $this->fulfillment($this->settings(), $client, blockedReleases: $blocked, searchRows: [
            $this->searchRow(id: 111, dl: 'dl-hash-111'),
            $this->searchRow(id: 222, dl: 'dl-hash-222'),
        ])->tryFulfill($job, $this->plan(), 'Red Rising');

        self::assertTrue($ok);
        self::assertSame('222', $job->getSourceId(), 'the blocked winner must be skipped for the runner-up');
        self::assertSame('mam', $job->getSource());
        self::assertSame('added-hash', $job->getClientRef());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertStringContainsString('dl-hash-222', (string) $client->added[0][0]);
    }

    public function testTryFulfillReturnsFalseWhenEverythingIsBlocked(): void
    {
        $blocked = $this->createStub(BlockedReleaseRepository::class);
        $blocked->method('blockedKeysForBook')->willReturn(['mam|111' => true]);

        $ok = $this->fulfillment($this->settings(), $this->downloadClient(), blockedReleases: $blocked, searchRows: [
            $this->searchRow(id: 111, dl: 'dl-hash-111'),
        ])->tryFulfill($this->job(), $this->plan(), 'Red Rising');

        self::assertFalse($ok);
        self::assertNotContains('add', $this->events);
    }

    public function testTryFulfillSpendsWedgePerConfigDecision(): void
    {
        // alwaysUseWedge + a not-free release → the auto pipeline spends a wedge.
        $settings = $this->settings(new MyAnonamouseConfig(enabled: true, baseUrl: self::BASE_URL, alwaysUseWedge: true));

        $ok = $this->fulfillment($settings, $this->downloadClient(), searchRows: [
            $this->searchRow(id: 333, dl: 'dl-hash-333'),
        ])->tryFulfill($this->job(), $this->plan(), 'Red Rising');

        self::assertTrue($ok);
        self::assertSame(['search', 'wedge', 'torrent-file', 'add'], $this->events);
        self::assertStringContainsString('torrentid=333', implode("\n", $this->mamUrls));
    }

    public function testTryFulfillNeverWedgesAFreeleechReleaseEvenWhenAlwaysUseIsOn(): void
    {
        $settings = $this->settings(new MyAnonamouseConfig(enabled: true, baseUrl: self::BASE_URL, alwaysUseWedge: true));

        $ok = $this->fulfillment($settings, $this->downloadClient(), searchRows: [
            $this->searchRow(id: 333, dl: 'dl-hash-333', free: true),
        ])->tryFulfill($this->job(), $this->plan(), 'Red Rising');

        self::assertTrue($ok);
        self::assertSame(['search', 'torrent-file', 'add'], $this->events);
    }

    public function testTryFulfillReturnsFalseOnAnEmptySearch(): void
    {
        $ok = $this->fulfillment($this->settings(), $this->downloadClient(), searchRows: [])
            ->tryFulfill($this->job(), $this->plan(), 'Red Rising');

        self::assertFalse($ok);
        self::assertSame(['search'], $this->events);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $searchRows
     */
    private function fulfillment(
        FakeMyAnonamouseSettings $settings,
        DownloadClientInterface $client,
        bool $wedgeSucceeds = true,
        bool $torrentFileOk = true,
        ?BlockedReleaseRepository $blockedReleases = null,
        array $searchRows = [],
    ): MamFulfillment {
        $http = new MockHttpClient(function (string $method, string $url) use ($wedgeSucceeds, $torrentFileOk, $searchRows): MockResponse {
            $this->mamUrls[] = $url;
            if (str_contains($url, 'loadSearchJSONbasic.php')) {
                $this->events[] = 'search';

                return new MockResponse(json_encode(['data' => $searchRows, 'found' => \count($searchRows)], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, 'bonusBuy.php')) {
                $this->events[] = 'wedge';

                return new MockResponse(json_encode(['success' => $wedgeSucceeds], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/tor/download.php/')) {
                $this->events[] = 'torrent-file';

                return $torrentFileOk
                    ? new MockResponse(self::TORRENT_BYTES, ['response_headers' => ['content-type' => 'application/x-bittorrent']])
                    : new MockResponse('<html>Login required</html>', ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('unexpected MAM request: ' . $url);
        });

        // grab() never reads the Prowlarr config; tryFulfill() does, via
        // findByKind(). The repository is final, so seed its private memo with a
        // null prowlarr row — that short-circuits before the entity manager and
        // yields ProwlarrConfig::default().
        $integrations = (new \ReflectionClass(IntegrationRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(IntegrationRepository::class, 'memo'))
            ->setValue($integrations, [Integration::KIND_PROWLARR => null]);

        if ($blockedReleases === null) {
            $blockedReleases = $this->createStub(BlockedReleaseRepository::class);
            $blockedReleases->method('blockedKeysForBook')->willReturn([]);
        }

        return new MamFulfillment(
            [$client],
            new MyAnonamouseClient($http, $settings, new NullLogger(), 0),
            $settings,
            new TorrentMatchScorer(new MatchScorer()),
            $integrations,
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
            $blockedReleases,
            new NullLogger(),
        );
    }

    private function settings(?MyAnonamouseConfig $config = null): FakeMyAnonamouseSettings
    {
        return new FakeMyAnonamouseSettings(
            $config ?? new MyAnonamouseConfig(enabled: true, baseUrl: self::BASE_URL),
        );
    }

    private function job(): DownloadJob
    {
        return new DownloadJob('seed', 'seed-id', ReleaseCandidate::PROTOCOL_TORRENT);
    }

    private function plan(): ReleaseSearchPlan
    {
        $book = new Book('test', 'ext-1', 'Red Rising');
        $book->setAuthor('Pierce Brown');
        (new \ReflectionProperty(Book::class, 'id'))->setValue($book, 7);

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: [],
            author: 'Pierce Brown',
            titleVariants: ['Red Rising'],
            contentType: ReleaseCandidate::CONTENT_EBOOK,
        );
    }

    private function candidate(
        bool $free = false,
        bool $flVip = false,
        bool $personalFreeleech = false,
    ): ReleaseCandidate {
        return new ReleaseCandidate(
            source: 'mam',
            sourceId: '123456',
            title: 'Red Rising [ENG / EPUB]',
            format: 'epub',
            sizeBytes: 123_456_789,
            downloadUrl: self::BASE_URL . '/tor/download.php/dl-hash-abc',
            infoUrl: self::BASE_URL . '/t/123456',
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: 'MyAnonamouse',
            seeders: 42,
            contentType: ReleaseCandidate::CONTENT_EBOOK,
            author: 'Pierce Brown',
            extra: [
                'mam' => [
                    'torrentId'         => 123456,
                    'dlHash'            => 'dl-hash-abc',
                    'free'              => $free,
                    'flVip'             => $flVip,
                    'personalFreeleech' => $personalFreeleech,
                ],
            ],
        );
    }

    /**
     * One MAM search-endpoint row, in the raw JSON shape mapRelease() consumes.
     *
     * @return array<string, mixed>
     */
    private function searchRow(int $id, string $dl, bool $free = false): array
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
            'fl_vip'             => 0,
            'free'               => $free ? 1 : 0,
            'personal_freeleech' => 0,
            'dl'                 => $dl,
            'added'              => '2024-05-01 12:34:56',
        ];
    }

    private function downloadClient(bool $configured = true): DownloadClientInterface
    {
        $events = &$this->events;

        return new class($configured, $events) implements DownloadClientInterface {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $added = [];

            /** @var list<string> */
            private array $events;

            /** @param list<string> $events */
            public function __construct(private readonly bool $configured, array &$events)
            {
                $this->events = &$events;
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function getProtocol(): string
            {
                return ReleaseCandidate::PROTOCOL_TORRENT;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function testConnection(): array
            {
                return [true, 'ok'];
            }

            public function addDownload(string $url, string $name, array $options = []): string
            {
                $this->events[] = 'add';
                $this->added[] = [$url, $name, $options];

                return 'added-hash';
            }

            public function getStatus(string $downloadId): DownloadStatus
            {
                throw new \LogicException('not used');
            }

            public function cancel(string $downloadId, bool $deleteFiles = false): bool
            {
                throw new \LogicException('not used');
            }

            public function listDownloads(): array
            {
                return [];
            }
        };
    }
}
