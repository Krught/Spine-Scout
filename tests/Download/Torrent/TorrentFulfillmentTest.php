<?php

declare(strict_types=1);

namespace App\Tests\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FulfillmentLog;
use App\Download\Torrent\TorrentFulfillment;
use App\Entity\DownloadJob;
use App\Integration\Prowlarr\ProwlarrClient;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\TorrentMatchScorer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the "grab" half of TorrentFulfillment — stamping a job with an
 * already-chosen release and handing its magnet to the torrent download client.
 * The interactive search UI calls grab() directly with a user-picked candidate,
 * so the stamping contract has to hold independently of the search/rank path.
 */
final class TorrentFulfillmentTest extends TestCase
{
    private const MAGNET = 'magnet:?xt=urn:btih:3601266b0873bfc80fd1f782632b38f9a60bf5a1&dn=Red+Rising';

    public function testGrabStampsJobAndSetsClientRef(): void
    {
        $client = $this->client('abc123');
        $job    = $this->job();

        self::assertTrue($this->fulfillment($client)->grab($job, $this->candidate(), 'Red Rising'));

        self::assertSame('torrent', $job->getSource());
        self::assertSame('prowlarr-guid-42', $job->getSourceId());
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $job->getProtocol());
        self::assertSame('epub', $job->getFormat());
        self::assertSame(123456, $job->getSizeBytes());
        self::assertSame([self::MAGNET], $job->getCandidateLinks());
        self::assertSame(self::MAGNET, $job->getDownloadUrl());
        self::assertSame('abc123', $job->getClientRef());
        self::assertSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
        self::assertSame(0, $job->getProgress());

        self::assertSame([[self::MAGNET, 'Red Rising']], $client->added);
    }

    public function testGrabTruncatesSourceIdAndFormatToColumnWidths(): void
    {
        $job = $this->job();
        $candidate = $this->candidate(
            sourceId: str_repeat('g', 300),
            format: 'a-very-long-format-name',
        );

        self::assertTrue($this->fulfillment($this->client())->grab($job, $candidate, 'Piranesi'));

        self::assertSame(255, mb_strlen($job->getSourceId()));
        self::assertSame(16, mb_strlen((string) $job->getFormat()));
        self::assertSame('a-very-long-form', $job->getFormat());
    }

    public function testGrabReturnsFalseWhenNoTorrentClientIsConfigured(): void
    {
        $job = $this->job();

        // An http client and an unconfigured torrent client: neither qualifies.
        $fulfillment = $this->fulfillment(
            $this->client(protocol: ReleaseCandidate::PROTOCOL_HTTP),
            $this->client(configured: false),
        );

        self::assertFalse($fulfillment->grab($job, $this->candidate(), 'Red Rising'));

        // Nothing stamped — the caller can fall through to another source.
        self::assertSame('seed', $job->getSource());
        self::assertNull($job->getClientRef());
        self::assertNull($job->getDownloadUrl());
    }

    public function testGrabLetsDownloadClientFailureBubbleUpWithoutMarkingDownloading(): void
    {
        $job = $this->job();
        $client = $this->client(throw: new \RuntimeException('qBittorrent rejected the add'));

        try {
            $this->fulfillment($client)->grab($job, $this->candidate(), 'Fourth Wing');
            self::fail('expected the download-client failure to bubble up');
        } catch (\RuntimeException $e) {
            self::assertSame('qBittorrent rejected the add', $e->getMessage());
        }

        // The release stamp lands before the add, but the job never flips to
        // DOWNLOADING and never gets a bogus client ref.
        self::assertSame('torrent', $job->getSource());
        self::assertSame(self::MAGNET, $job->getDownloadUrl());
        self::assertNull($job->getClientRef());
        self::assertNotSame(DownloadJob::STATUS_DOWNLOADING, $job->getStatus());
    }

    // --- helpers ----------------------------------------------------------

    private function fulfillment(DownloadClientInterface ...$clients): TorrentFulfillment
    {
        // grab() touches neither the indexers, the scorer nor the integration
        // repository; they are final classes, so hand over uninitialized instances.
        $reflect = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new TorrentFulfillment(
            $clients,
            $reflect(ProwlarrClient::class),
            $reflect(TorrentMatchScorer::class),
            $reflect(IntegrationRepository::class),
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
        );
    }

    private function job(): DownloadJob
    {
        return new DownloadJob('seed', 'seed-id', ReleaseCandidate::PROTOCOL_TORRENT);
    }

    private function candidate(string $sourceId = 'prowlarr-guid-42', ?string $format = 'epub'): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: 'prowlarr',
            sourceId: $sourceId,
            title: 'Red Rising',
            format: $format,
            sizeBytes: 123456,
            downloadUrl: self::MAGNET,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: 'SomeTracker',
            seeders: 42,
        );
    }

    private function client(
        string $hash = 'hash',
        string $protocol = ReleaseCandidate::PROTOCOL_TORRENT,
        bool $configured = true,
        ?\Throwable $throw = null,
    ): DownloadClientInterface {
        return new class($hash, $protocol, $configured, $throw) implements DownloadClientInterface {
            /** @var list<array{0: string, 1: string}> */
            public array $added = [];

            public function __construct(
                private readonly string $hash,
                private readonly string $protocol,
                private readonly bool $configured,
                private readonly ?\Throwable $throw,
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
                return ['ok' => true];
            }

            public function addDownload(string $url, string $name, array $options = []): string
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $this->added[] = [$url, $name];

                return $this->hash;
            }

            public function getStatus(string $downloadId): DownloadStatus
            {
                throw new \LogicException('not used');
            }

            public function cancel(string $downloadId, bool $deleteFiles = false): bool
            {
                throw new \LogicException('not used');
            }
        };
    }
}
