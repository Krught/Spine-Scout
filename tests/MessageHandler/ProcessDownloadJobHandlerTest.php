<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Bypass\BypassResolver;
use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\Client\HttpDownloadClient;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Mam\MamFulfillment;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Metadata\EpubMetadataWriter;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Service\AppSettingsProvider;
use App\Service\BookCoverProvider;
use App\Tests\Integration\MyAnonamouse\FakeMyAnonamouseSettings;
use Doctrine\DBAL\Connection;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Message\ProcessDownloadJob;
use App\MessageHandler\ProcessDownloadJobHandler;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\DirectDownload\DirectDownloadCascade;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\Match\MatchScorer;
use App\Search\Torrent\TorrentMatchScorer;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use App\Search\DirectDownload\DirectDownloadConfig as Cfg;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProcessDownloadJobHandlerTest extends TestCase
{
    private string $root;
    private DownloadJob $currentJob;

    /** Ordered record of what the fake MAM transport served: 'search', 'torrent-file'. */
    private array $mamEvents = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spinescout_proc_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->mamEvents = [];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testDownloadsWithFailoverMovesToOutputAndCompletes(): void
    {
        // First link 404s, second succeeds — exercises per-attempt link failover.
        $http = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('BOOKBYTES'),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());
        $outDir = $this->root . '/library';

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler([$client], $outDir, ['https://m.test/fail', 'https://m.test/ok'], format: 'epub');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertSame('complete', $job->getBookRequest()?->getDeliveryStatus());
        self::assertSame(100, $job->getProgress());
        self::assertSame($outDir . '/Pierce Brown - Red Rising (2014).epub', $job->getFilePath());
        self::assertFileExists((string) $job->getFilePath());
        self::assertSame('BOOKBYTES', file_get_contents((string) $job->getFilePath()));
        // The job was stamped with the winning source/item from the cascade.
        self::assertSame('libgen', $job->getSource());
        self::assertSame('hash123', $job->getSourceId());
    }

    public function testFormatNotInPriorityListIsNeverDownloaded(): void
    {
        // The source reports an odd format ('raw') that isn't in the policy's
        // format-priority allow-list. It must be filtered before download — no file
        // should ever land in the library — and the job errored.
        $http = new MockHttpClient([new MockResponse('BOOKBYTES')]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());
        $outDir = $this->root . '/library';

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler([$client], $outDir, ['https://m.test/ok'], format: 'raw');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertNull($job->getFilePath());
        self::assertFalse(is_dir($outDir) && (scandir($outDir) ?: []) !== ['.', '..'], 'No file should be written to the library.');
    }

    public function testAllLinksFailingMarksJobError(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $handler = $this->handler([$client], $this->root . '/library', ['https://m.test/a', 'https://m.test/b'], format: 'epub');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertSame('error', $job->getBookRequest()?->getDeliveryStatus());
        self::assertNotNull($job->getStatusMessage());
    }

    public function testTotalFailureBlocksEveryAttemptedCandidate(): void
    {
        // Every mirror/link of the only candidate fails → on the final failure the
        // handler must blocklist that candidate for the book (keyed candidate
        // source|sourceId, so the cascade skips it on the next sweep).
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $book = $job->getBookRequest()?->getBook();

        $blocked = $this->createMock(BlockedReleaseRepository::class);
        $blocked->expects(self::once())->method('blockRelease')->with(
            self::identicalTo($book),
            'libgen',
            'hash123',
            'http',
            null,
            null,
            self::callback(static fn (mixed $reason): bool => \is_string($reason) && $reason !== ''),
        );

        $handler = $this->handler([$client], $this->root . '/library', ['https://m.test/a', 'https://m.test/b'], format: 'epub', blockedReleases: $blocked);
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
    }

    public function testDisallowedDownloadedFormatBlocksTheCandidateImmediately(): void
    {
        // The candidate's bytes downloaded fine but as a format outside the
        // allow-list: the release itself is proven bad, so it is blocked right in
        // the loop (not only at total-failure time) with the format reason.
        // The cascade normally gates disallowed formats before download, so this
        // handler-side safety net only fires when the two policy reads disagree
        // (the operator changed the format list mid-run) — simulated here by
        // serving the cascade a permissive policy and the handler a strict one.
        $http = new MockHttpClient([new MockResponse('BOOKBYTES')]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $book = $job->getBookRequest()?->getBook();

        $blocked = $this->createMock(BlockedReleaseRepository::class);
        $blocked->expects(self::once())->method('blockRelease')->with(
            self::identicalTo($book),
            'libgen',
            'hash123',
            'http',
            null,
            null,
            "Downloaded file format 'raw' is not in the format priority list.",
        );

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/ok'],
            format: 'raw',
            blockedReleases: $blocked,
            policies: [
                new BestMatchPolicy(minMatchScore: 0),                     // handler gate: default allow-list, 'raw' disallowed
                new BestMatchPolicy(formatPriority: [], minMatchScore: 0), // cascade: allow-all, so the candidate gets attempted
            ],
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
    }

    public function testSuccessfulDownloadBlocksNothing(): void
    {
        // Failed links along the way are only blocked when the whole cascade
        // fails; a later success for the same candidate proves it good.
        $http = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('BOOKBYTES'),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $blocked = $this->createMock(BlockedReleaseRepository::class);
        $blocked->expects(self::never())->method('blockRelease');

        $handler = $this->handler([$client], $this->root . '/library', ['https://m.test/fail', 'https://m.test/ok'], format: 'epub', blockedReleases: $blocked);
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
    }

    public function testMissingOutputDirectoryMarksJobError(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('DATA')), $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $handler = $this->handler([$client], outputDir: '', links: ['https://m.test/ok'], format: 'epub');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertStringContainsString('watch folder', strtolower((string) $job->getStatusMessage()));
    }

    public function testNonQueuedJobIsSkipped(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('DATA')), $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());
        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $job->setStatus(DownloadJob::STATUS_COMPLETE); // already done

        $handler = $this->handler([$client], $this->root . '/library', ['https://m.test/ok'], format: 'epub');
        $handler(new ProcessDownloadJob(1));

        // Untouched: no download attempted, status unchanged.
        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertNull($job->getFilePath());
    }

    private function job(string $title, string $author, string $year): DownloadJob
    {
        $book = new Book('grimmory', 'ext-1', $title);
        $book->setAuthor($author);
        $book->setPublishedDate($year);
        $request = new BookRequest(new User('admin'), $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);

        // Placeholder source/sourceId — the cascade stamps the real values on success.
        $job = new DownloadJob('pending', '', 'http', $request);
        $job->setStatus(DownloadJob::STATUS_QUEUED);

        $this->currentJob = $job;

        return $job;
    }

    /**
     * @param list<\App\Download\Client\DownloadClientInterface> $clients
     * @param list<string>                                       $links  links the (single) cascade attempt offers
     */
    /**
     * @param list<BestMatchPolicy>|null $policies Policies served to consecutive
     *        getBestMatchPolicy() calls (1st: the handler's format gate, 2nd: the
     *        cascade). Null = one permissive policy for both.
     */
    private function handler(array $clients, string $outputDir, array $links, string $format, ?TorrentFulfillmentInterface $torrent = null, ?array $priority = null, ?BlockedReleaseRepository $blockedReleases = null, ?array $policies = null, ?MamFulfillment $mam = null, string $mirrorSource = 'libgen'): ProcessDownloadJobHandler
    {
        $blockedReleases ??= $this->createStub(BlockedReleaseRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());
        $em->method('find')->willReturnCallback(fn () => $this->currentJob);

        // Built via the constructor (not fromArray) so an empty $outputDir stays
        // empty — fromArray would substitute the default output directory.
        $config = new DirectDownloadConfig(
            $priority ?? [['id' => $mirrorSource, 'enabled' => true]],
            [$mirrorSource => \App\Mirror\MirrorList::fromRaw(['https://m.test'], new \App\Mirror\MirrorListNormalizer())],
            false,
            $outputDir,
            DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE,
        );

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        if ($policies !== null) {
            $settings->method('getBestMatchPolicy')->willReturnOnConsecutiveCalls(...$policies);
        } else {
            $settings->method('getBestMatchPolicy')->willReturn(new BestMatchPolicy(minMatchScore: 0));
        }

        $log = new FulfillmentLog($this->createStub(Connection::class), new NullLogger());

        $source = new CascadeFakeSource($mirrorSource, $format, $links);
        $cascade = new DirectDownloadCascade([$source], new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings, $log, $blockedReleases);

        // Injector wired with the toggle off, so downloads in these tests are moved
        // byte-for-byte (the metadata-rewrite path has its own dedicated test).
        $appSettings = $this->createStub(AppSettingsProvider::class);
        $appSettings->method('isMetadataOverwriteEnabled')->willReturn(false);
        $injector = new EbookMetadataInjector(
            $appSettings,
            new EpubMetadataWriter(),
            $this->createStub(BookCoverProvider::class),
            new NullLogger(),
        );

        return new ProcessDownloadJobHandler(
            $em,
            $clients,
            $cascade,
            $settings,
            new FileMover(),
            new FilenameTemplate(),
            $injector,
            $torrent ?? $this->noTorrent(),
            $mam ?? $this->mamFulfillment(enabled: false),
            $log,
            new NullLogger(),
            $blockedReleases,
        );
    }

    public function testTorrentFirstSourceHandsJobToPollerWithoutHttp(): void
    {
        // Torrent is the highest-priority enabled source and is available → the book
        // is handed to the torrent poller (DOWNLOADING + client_ref) and the HTTP
        // cascade is never reached.
        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $torrent = new class implements TorrentFulfillmentInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
            {
                $job->setProtocol(ReleaseCandidate::PROTOCOL_TORRENT)
                    ->setClientRef('deadbeefdeadbeef')
                    ->setStatus(DownloadJob::STATUS_DOWNLOADING);

                return true;
            }

            public function grab(DownloadJob $job, ReleaseCandidate $candidate, string $subject): bool
            {
                return false;
            }
        };

        $handler = $this->handler([], $this->root . '/library', [], 'epub', $torrent, [['id' => 'torrent', 'enabled' => true], ['id' => 'libgen', 'enabled' => true]]);
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame('deadbeefdeadbeef', $job->getClientRef());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $job->getProtocol());
    }

    public function testMamFirstSourceHandsJobToPollerWithoutHttpOrTorrent(): void
    {
        // MAM is the highest-priority enabled source and available → the job is
        // grabbed from MAM (source=mam + client_ref + DOWNLOADING) and neither the
        // HTTP cascade nor the torrent source is ever reached.
        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');
        $torrent = $this->recordingTorrent();

        $handler = $this->handler(
            [],
            $this->root . '/library',
            [],
            'epub',
            torrent: $torrent,
            priority: [['id' => 'mam', 'enabled' => true], ['id' => 'annas_archive', 'enabled' => true], ['id' => 'torrent', 'enabled' => true]],
            mam: $this->mamFulfillment(searchRows: [$this->mamSearchRow(id: 4242, dl: 'dl-hash-42')]),
            mirrorSource: 'annas_archive',
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame('mam', $job->getSource());
        self::assertSame('4242', $job->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $job->getProtocol());
        self::assertSame('mam-added-hash', $job->getClientRef());
        self::assertSame(['search', 'torrent-file'], $this->mamEvents);
        self::assertFalse($torrent->called, 'a MAM success must stop the walk before the torrent source');
    }

    public function testMamFailureFallsThroughToCascadeThenTorrent(): void
    {
        // Priority [mam, annas_archive, torrent]: MAM finds nothing, the HTTP
        // cascade fails on its only link, and torrent — ranked below the mirror
        // source — runs after the cascade and wins.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');
        $torrent = $this->recordingTorrent(succeeds: true);

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/fail'],
            'epub',
            torrent: $torrent,
            priority: [['id' => 'mam', 'enabled' => true], ['id' => 'annas_archive', 'enabled' => true], ['id' => 'torrent', 'enabled' => true]],
            mam: $this->mamFulfillment(searchRows: []),
            mirrorSource: 'annas_archive',
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(['search'], $this->mamEvents, 'MAM ran (and found nothing) before the cascade');
        self::assertSame(1, $http->getRequestsCount(), 'the HTTP cascade must have been attempted');
        self::assertTrue($torrent->called, 'torrent is ranked below the mirror source, so it runs after the cascade');
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame('deadbeefdeadbeef', $job->getClientRef());
    }

    public function testMamAfterMirrorsRunsOnlyAfterTheCascadeFails(): void
    {
        // Priority [libgen, mam]: the cascade runs first; when it produces nothing
        // the MAM fallback grabs the release.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/fail'],
            'epub',
            priority: [['id' => 'libgen', 'enabled' => true], ['id' => 'mam', 'enabled' => true]],
            mam: $this->mamFulfillment(searchRows: [$this->mamSearchRow(id: 4242, dl: 'dl-hash-42')]),
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame('mam', $job->getSource());
        self::assertSame('mam-added-hash', $job->getClientRef());
        self::assertSame(1, $http->getRequestsCount(), 'the cascade link was attempted before the MAM fallback');
    }

    public function testMamAfterMirrorsIsSkippedWhenTheCascadeSucceeds(): void
    {
        $http = new MockHttpClient([new MockResponse('BOOKBYTES')]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/ok'],
            'epub',
            priority: [['id' => 'libgen', 'enabled' => true], ['id' => 'mam', 'enabled' => true]],
            mam: $this->mamFulfillment(searchRows: [$this->mamSearchRow(id: 4242, dl: 'dl-hash-42')]),
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertSame('libgen', $job->getSource());
        self::assertSame([], $this->mamEvents, 'a cascade success must never reach the MAM fallback');
    }

    public function testUnavailableMamIsSkippedEntirely(): void
    {
        // MAM is first in priority but the integration is disabled (isAvailable()
        // false) → it is never contacted and the cascade proceeds as usual.
        $http = new MockHttpClient([new MockResponse('BOOKBYTES')]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/ok'],
            'epub',
            priority: [['id' => 'mam', 'enabled' => true], ['id' => 'libgen', 'enabled' => true]],
            mam: $this->mamFulfillment(enabled: false, searchRows: [$this->mamSearchRow(id: 4242, dl: 'dl-hash-42')]),
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertSame('libgen', $job->getSource());
        self::assertSame([], $this->mamEvents, 'an unavailable MAM must never be contacted');
    }

    public function testMamThrowingIsNonFatalAndTheWalkContinues(): void
    {
        // MAM finds a release but refuses to serve the .torrent file (expired
        // cookie → grab() throws). The handler logs a warning and falls through to
        // the cascade, which completes the job.
        $http = new MockHttpClient([new MockResponse('BOOKBYTES')]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler(
            [$client],
            $this->root . '/library',
            ['https://m.test/ok'],
            'epub',
            priority: [['id' => 'mam', 'enabled' => true], ['id' => 'libgen', 'enabled' => true]],
            mam: $this->mamFulfillment(searchRows: [$this->mamSearchRow(id: 4242, dl: 'dl-hash-42')], torrentFileOk: false),
        );
        $handler(new ProcessDownloadJob(1));

        self::assertSame(['search', 'torrent-file'], $this->mamEvents, 'MAM was tried and threw on the .torrent fetch');
        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertSame('libgen', $job->getSource());
    }

    /**
     * A real MamFulfillment over a mocked MAM transport (the class is final, so it
     * cannot be stubbed): searchRows drive tryFulfill's search, torrentFileOk
     * controls whether the .torrent fetch succeeds (false → grab() throws), and
     * enabled: false makes isAvailable() report the integration as unusable.
     *
     * @param list<array<string, mixed>> $searchRows
     */
    private function mamFulfillment(bool $enabled = true, array $searchRows = [], bool $torrentFileOk = true): MamFulfillment
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($searchRows, $torrentFileOk): MockResponse {
            if (str_contains($url, 'loadSearchJSONbasic.php')) {
                $this->mamEvents[] = 'search';

                return new MockResponse(json_encode(['data' => $searchRows, 'found' => \count($searchRows)], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/tor/download.php/')) {
                $this->mamEvents[] = 'torrent-file';

                return $torrentFileOk
                    ? new MockResponse('d8:announce20:https://mam.test/ann4:infod4:name3:fooee', ['response_headers' => ['content-type' => 'application/x-bittorrent']])
                    : new MockResponse('<html>Login required</html>', ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('unexpected MAM request: ' . $url);
        });

        $settings = new FakeMyAnonamouseSettings(new MyAnonamouseConfig(enabled: $enabled, baseUrl: 'https://mam.test'));

        // tryFulfill() reads the shared Prowlarr match policy via findByKind(); the
        // repository is final, so seed its private memo with a null prowlarr row —
        // that short-circuits before the entity manager and yields the default.
        $integrations = (new \ReflectionClass(IntegrationRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(IntegrationRepository::class, 'memo'))
            ->setValue($integrations, [Integration::KIND_PROWLARR => null]);

        $blocked = $this->createStub(BlockedReleaseRepository::class);
        $blocked->method('blockedKeysForBook')->willReturn([]);

        return new MamFulfillment(
            [$this->mamTorrentClient()],
            new MyAnonamouseClient($http, $settings, new NullLogger(), 0),
            $settings,
            new TorrentMatchScorer(new MatchScorer()),
            $integrations,
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
            $blocked,
            new NullLogger(),
        );
    }

    /**
     * One MAM search-endpoint row in the raw JSON shape mapRelease() consumes,
     * matching the test book so the scorer ranks it.
     *
     * @return array<string, mixed>
     */
    private function mamSearchRow(int $id, string $dl): array
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
            'free'               => 0,
            'personal_freeleech' => 0,
            'dl'                 => $dl,
            'added'              => '2024-05-01 12:34:56',
        ];
    }

    /** Torrent-protocol download client for the MAM fulfillment: accepts every add. */
    private function mamTorrentClient(): DownloadClientInterface
    {
        return new class implements DownloadClientInterface {
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
                return true;
            }

            public function testConnection(): array
            {
                return [true, 'ok'];
            }

            public function addDownload(string $url, string $name, array $options = []): string
            {
                return 'mam-added-hash';
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

    /**
     * Torrent fulfillment that records whether it was invoked; when $succeeds it
     * stamps the job the way TorrentFulfillment does and reports the add.
     */
    private function recordingTorrent(bool $succeeds = false): RecordingTorrentFulfillment
    {
        return new RecordingTorrentFulfillment($succeeds);
    }

    /** Torrent fulfillment that is never available — these tests exercise the HTTP cascade. */
    private function noTorrent(): TorrentFulfillmentInterface
    {
        return new class implements TorrentFulfillmentInterface {
            public function isAvailable(): bool
            {
                return false;
            }

            public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
            {
                return false;
            }

            public function grab(DownloadJob $job, ReleaseCandidate $candidate, string $subject): bool
            {
                return false;
            }
        };
    }

    /** No-op bypass resolver (mode none): the download client never invokes a bypasser. */
    private function bypassResolver(): BypassResolver
    {
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn(new DirectDownloadConfig([], []));
        $settings->method('getBestMatchPolicy')->willReturn(BestMatchPolicy::default());

        return new BypassResolver([], $settings, new NullLogger());
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Available torrent fulfillment that records whether tryFulfill was invoked;
 * when $succeeds it stamps the job the way TorrentFulfillment does.
 */
final class RecordingTorrentFulfillment implements TorrentFulfillmentInterface
{
    public bool $called = false;

    public function __construct(private readonly bool $succeeds)
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
    {
        $this->called = true;
        if (!$this->succeeds) {
            return false;
        }
        $job->setProtocol(ReleaseCandidate::PROTOCOL_TORRENT)
            ->setClientRef('deadbeefdeadbeef')
            ->setStatus(DownloadJob::STATUS_DOWNLOADING);

        return true;
    }

    public function grab(DownloadJob $job, ReleaseCandidate $candidate, string $subject): bool
    {
        return false;
    }
}

/**
 * Fake source for the handler test: yields one qualifying candidate whose detail
 * links are the test's links (so the cascade produces a single attempt offering
 * them, mirror-matched so they are reused without an extra request).
 */
final class CascadeFakeSource implements ReleaseSourceInterface
{
    /** @param list<string> $links */
    public function __construct(
        private readonly string $id,
        private readonly string $format,
        private readonly array $links,
    ) {
    }

    public function getName(): string
    {
        return $this->id;
    }

    public function sourceId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return ucfirst($this->id);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnavailableReason(): ?string
    {
        return null;
    }

    public function search(ReleaseSearchPlan $plan, ?Cfg $config = null): array
    {
        return $this->searchVia('https://m.test', $plan, $config);
    }

    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?Cfg $config = null): array
    {
        return [new ReleaseCandidate(
            source: $this->id,
            sourceId: 'hash123',
            title: $plan->primaryTitle(),
            format: $this->format,
            protocol: ReleaseCandidate::PROTOCOL_HTTP,
            author: $plan->author,
            extra: ['mirror' => 'https://m.test'],
        )];
    }

    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string
    {
        return $mirror . '/q';
    }

    public function searchPlanUrl(ReleaseSearchPlan $plan, ?Cfg $config = null): array
    {
        return ['mirror' => 'https://m.test', 'url' => 'https://m.test/q'];
    }

    public function resolveDetail(ReleaseCandidate $candidate, ?Cfg $config = null): array
    {
        return ['isbns' => [], 'raw' => [], 'links' => $this->links, 'error' => null];
    }

    public function linksVia(ReleaseCandidate $item, string $mirror, ?Cfg $config = null): array
    {
        return $this->links;
    }
}
