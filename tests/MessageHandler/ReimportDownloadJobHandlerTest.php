<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FulfillmentLog;
use App\Download\Torrent\TorrentFinalizerInterface;
use App\Entity\DownloadJob;
use App\Message\ReimportDownloadJob;
use App\MessageHandler\ReimportDownloadJobHandler;
use App\Search\Source\ReleaseCandidate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the reimport handler's guard chain: every precondition failure
 * (missing job, wrong status, wrong protocol, no client, files gone) must skip
 * quietly without touching the finalizer, and only a completed torrent job whose
 * raw files are still in the download client reaches finalize().
 */
final class ReimportDownloadJobHandlerTest extends TestCase
{
    public function testReimportsACompletedTorrentWhoseFilesAreStillAvailable(): void
    {
        $job = $this->completedTorrentJob();
        $seeding = $this->seedingStatus();
        $client = $this->client($seeding);
        $finalizer = $this->finalizer(available: '/downloads/Red Rising');

        $this->handler($job, $finalizer, $client)(new ReimportDownloadJob(7));

        self::assertCount(1, $finalizer->finalized);
        [$finalizedJob, $status, $subject, $finalizedClient] = $finalizer->finalized[0];
        self::assertSame($job, $finalizedJob);
        self::assertSame($seeding, $status);
        self::assertSame('guid-1', $subject); // No book request — falls back to the source id.
        self::assertSame($client, $finalizedClient);
    }

    public function testSkipsWhenJobDoesNotExist(): void
    {
        $finalizer = $this->finalizer(available: '/downloads/Red Rising');

        $this->handler(null, $finalizer, $this->client($this->seedingStatus()))(new ReimportDownloadJob(404));

        self::assertSame([], $finalizer->finalized);
    }

    public function testSkipsWhenJobIsNotComplete(): void
    {
        $job = $this->completedTorrentJob()->setStatus(DownloadJob::STATUS_DOWNLOADING);
        $client = $this->client($this->seedingStatus());
        $finalizer = $this->finalizer(available: '/downloads/Red Rising');

        $this->handler($job, $finalizer, $client)(new ReimportDownloadJob(7));

        self::assertSame([], $finalizer->finalized);
        self::assertSame(0, $client->statusQueries);
    }

    public function testSkipsWhenJobIsNotATorrent(): void
    {
        $job = (new DownloadJob('annas-archive', 'guid-1', ReleaseCandidate::PROTOCOL_HTTP))
            ->setStatus(DownloadJob::STATUS_COMPLETE);
        $finalizer = $this->finalizer(available: '/downloads/Red Rising');

        $this->handler($job, $finalizer, $this->client($this->seedingStatus()))(new ReimportDownloadJob(7));

        self::assertSame([], $finalizer->finalized);
    }

    public function testSkipsWhenNoTorrentClientIsConfigured(): void
    {
        $job = $this->completedTorrentJob();
        $finalizer = $this->finalizer(available: '/downloads/Red Rising');

        // An http client and an unconfigured torrent client: neither qualifies.
        $handler = $this->handler(
            $job,
            $finalizer,
            $this->client($this->seedingStatus(), protocol: ReleaseCandidate::PROTOCOL_HTTP),
            $this->client($this->seedingStatus(), configured: false),
        );
        $handler(new ReimportDownloadJob(7));

        self::assertSame([], $finalizer->finalized);
    }

    public function testSkipsWhenTorrentFilesAreNoLongerAvailable(): void
    {
        $job = $this->completedTorrentJob();
        $client = $this->client($this->seedingStatus());
        $finalizer = $this->finalizer(available: null);

        $this->handler($job, $finalizer, $client)(new ReimportDownloadJob(7));

        self::assertSame([], $finalizer->finalized);
        // The status query for finalize() must not happen once availability said no.
        self::assertSame(0, $client->statusQueries);
    }

    // --- helpers ----------------------------------------------------------

    private function seedingStatus(): DownloadStatus
    {
        return new DownloadStatus(
            state: DownloadStatus::STATE_SEEDING,
            progress: 100.0,
            filePath: '/data/torrents/Red Rising',
            savePath: '/data/torrents',
        );
    }

    private function handler(
        ?DownloadJob $job,
        TorrentFinalizerInterface $finalizer,
        DownloadClientInterface ...$clients,
    ): ReimportDownloadJobHandler {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($job);

        return new ReimportDownloadJobHandler(
            $em,
            $clients,
            $finalizer,
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
            new NullLogger(),
        );
    }

    private function completedTorrentJob(): DownloadJob
    {
        return (new DownloadJob('torrent', 'guid-1', ReleaseCandidate::PROTOCOL_TORRENT))
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setClientRef('abc123');
    }

    /**
     * @return TorrentFinalizerInterface&object{finalized: list<array{0: DownloadJob, 1: DownloadStatus, 2: string, 3: DownloadClientInterface}>}
     */
    private function finalizer(?string $available): TorrentFinalizerInterface
    {
        return new class($available) implements TorrentFinalizerInterface {
            /** @var list<array{0: DownloadJob, 1: DownloadStatus, 2: string, 3: DownloadClientInterface}> */
            public array $finalized = [];

            public function __construct(private readonly ?string $available)
            {
            }

            public function finalize(DownloadJob $job, DownloadStatus $status, string $subject, DownloadClientInterface $client): void
            {
                $this->finalized[] = [$job, $status, $subject, $client];
            }

            public function sourceAvailability(DownloadJob $job, DownloadClientInterface $client): ?string
            {
                return $this->available;
            }

            public function fail(DownloadJob $job, string $message): void
            {
                throw new \LogicException('not used');
            }
        };
    }

    /** @return DownloadClientInterface&object{statusQueries: int} */
    private function client(
        DownloadStatus $status,
        string $protocol = ReleaseCandidate::PROTOCOL_TORRENT,
        bool $configured = true,
    ): DownloadClientInterface {
        return new class($status, $protocol, $configured) implements DownloadClientInterface {
            public int $statusQueries = 0;

            public function __construct(
                private readonly DownloadStatus $status,
                private readonly string $protocol,
                private readonly bool $configured,
            ) {
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function getProtocol(): string
            {
                return $this->protocol;
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
                throw new \LogicException('not used');
            }

            public function getStatus(string $downloadId): DownloadStatus
            {
                ++$this->statusQueries;

                return $this->status;
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
