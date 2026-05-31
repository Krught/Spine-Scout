<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Bypass\BypassResolver;
use App\Download\Client\HttpDownloadClient;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use Doctrine\DBAL\Connection;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Message\ProcessDownloadJob;
use App\MessageHandler\ProcessDownloadJobHandler;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\SearchSettingsProvider;
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
        // First link 404s, second succeeds — exercises link failover.
        $http = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('BOOKBYTES'),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());
        $outDir = $this->root . '/library';

        $job = $this->job(['https://m.test/fail', 'https://m.test/ok'], format: 'epub',
            title: 'Red Rising', author: 'Pierce Brown', year: '2014');

        $handler = $this->handler([$client], $outDir);
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertSame('complete', $job->getBookRequest()?->getDeliveryStatus());
        self::assertSame(100, $job->getProgress());
        self::assertNotNull($job->getFilePath());
        self::assertSame($outDir . '/Pierce Brown - Red Rising (2014).epub', $job->getFilePath());
        self::assertFileExists($job->getFilePath());
        self::assertSame('BOOKBYTES', file_get_contents($job->getFilePath()));
    }

    public function testAllLinksFailingMarksJobError(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $client = new HttpDownloadClient($http, $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(['https://m.test/a', 'https://m.test/b'], format: 'epub', title: 'X', author: 'Y', year: '2000');
        $handler = $this->handler([$client], $this->root . '/library');
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertSame('error', $job->getBookRequest()?->getDeliveryStatus());
        self::assertNotNull($job->getStatusMessage());
    }

    public function testMissingOutputDirectoryMarksJobError(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('DATA')), $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());

        $job = $this->job(['https://m.test/ok'], format: 'epub', title: 'X', author: 'Y', year: '2000');
        $handler = $this->handler([$client], outputDir: '');   // no output folder configured
        $handler(new ProcessDownloadJob(1));

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertStringContainsString('output', strtolower((string) $job->getStatusMessage()));
    }

    public function testNonQueuedJobIsSkipped(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('DATA')), $this->root . '/staging', new AAStyleHttpProtocol(), $this->bypassResolver());
        $job = $this->job(['https://m.test/ok'], format: 'epub', title: 'X', author: 'Y', year: '2000');
        $job->setStatus(DownloadJob::STATUS_COMPLETE); // already done

        $handler = $this->handler([$client], $this->root . '/library');
        $handler(new ProcessDownloadJob(1));

        // Untouched: no second download attempted, status unchanged.
        self::assertSame(DownloadJob::STATUS_COMPLETE, $job->getStatus());
        self::assertNull($job->getFilePath());
    }

    /**
     * @param list<string> $links
     */
    private function job(array $links, string $format, string $title, string $author, string $year): DownloadJob
    {
        $book = new Book('grimmory', 'ext-1', $title);
        $book->setAuthor($author);
        $book->setPublishedDate($year);
        $request = new BookRequest(new User('admin'), $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);

        $job = new DownloadJob('direct_http', 'hash123', 'http', $request);
        $job->setFormat($format);
        $job->setCandidateLinks($links);
        $job->setStatus(DownloadJob::STATUS_QUEUED);

        $this->currentJob = $job;

        return $job;
    }

    /**
     * @param list<\App\Download\Client\DownloadClientInterface> $clients
     */
    private function handler(array $clients, string $outputDir): ProcessDownloadJobHandler
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());
        $em->method('find')->willReturnCallback(fn () => $this->currentJob);

        $config = new DirectDownloadConfig([], [], false, $outputDir, DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE);
        $settings = $this->createStub(SearchSettingsProvider::class);
        $settings->method('getDirectDownloadConfig')->willReturn($config);
        $settings->method('getBestMatchPolicy')->willReturn(BestMatchPolicy::default());

        $log = new FulfillmentLog($this->createStub(Connection::class), new NullLogger());

        return new ProcessDownloadJobHandler(
            $em,
            $clients,
            $settings,
            new FileMover(),
            new FilenameTemplate(),
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
