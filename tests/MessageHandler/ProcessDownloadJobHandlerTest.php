<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Bypass\BypassResolver;
use App\Download\Client\HttpDownloadClient;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Metadata\EpubMetadataWriter;
use App\Service\AppSettingsProvider;
use App\Service\BookCoverProvider;
use Doctrine\DBAL\Connection;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Message\ProcessDownloadJob;
use App\MessageHandler\ProcessDownloadJobHandler;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\BestMatch\BestMatchSelector;
use App\Search\DirectDownload\DirectDownloadCascade;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\ReleaseSourceScorer;
use App\Search\Match\MatchScorer;
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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spinescout_proc_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
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

    public function testMissingOutputDirectoryMarksJobError(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('DATA')), $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(title: 'X', author: 'Y', year: '2000');
        $handler = $this->handler([$client], outputDir: '', links: ['https://m.test/ok'], format: 'epub');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertStringContainsString('output', strtolower((string) $job->getStatusMessage()));
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
    private function handler(array $clients, string $outputDir, array $links, string $format): ProcessDownloadJobHandler
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());
        $em->method('find')->willReturnCallback(fn () => $this->currentJob);

        // Built via the constructor (not fromArray) so an empty $outputDir stays
        // empty — fromArray would substitute the default output directory.
        $config = new DirectDownloadConfig(
            [['id' => 'libgen', 'enabled' => true]],
            ['libgen' => \App\Mirror\MirrorList::fromRaw(['https://m.test'], new \App\Mirror\MirrorListNormalizer())],
            false,
            $outputDir,
            DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE,
        );

        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn(new BestMatchPolicy(minMatchScore: 0));

        $log = new FulfillmentLog($this->createStub(Connection::class), new NullLogger());

        $source = new CascadeFakeSource('libgen', $format, $links);
        $cascade = new DirectDownloadCascade([$source], new ReleaseSourceScorer(new MatchScorer()), new BestMatchSelector(), $settings, $log);

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
            $log,
            new NullLogger(),
        );
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
