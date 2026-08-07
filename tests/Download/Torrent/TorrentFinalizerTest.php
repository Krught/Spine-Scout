<?php

declare(strict_types=1);

namespace App\Tests\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Metadata\AudiobookSidecarWriter;
use App\Download\Metadata\AudiobookTagWriter;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Torrent\TorrentFinalizer;
use App\Download\Torrent\TorrentMover;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit tests for TorrentFinalizer::sourceAvailability() — the "can this completed
 * download be re-imported?" probe behind the Reimport button. It must only report
 * a source when the download client still has the torrent AND the resolved local
 * path actually exists on disk; every doubt is a null, never a throw.
 */
final class TorrentFinalizerTest extends TestCase
{
    private const SAVE_PATH = '/data/torrents';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spinescout_finalizer_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testReturnsResolvedSourcePathWhileTorrentSeedsAndFilesExist(): void
    {
        mkdir($this->root . '/Red Rising', 0o775, true);
        file_put_contents($this->root . '/Red Rising/book.epub', 'EPUB');

        $client = $this->client(new DownloadStatus(
            state: DownloadStatus::STATE_SEEDING,
            progress: 100.0,
            filePath: self::SAVE_PATH . '/Red Rising',
            savePath: self::SAVE_PATH,
        ));

        self::assertSame(
            $this->root . '/Red Rising',
            $this->finalizer()->sourceAvailability($this->job(), $client),
        );
    }

    public function testResolvesNestedSingleFileContentPathsRelativeToTheSavePath(): void
    {
        // A single-file torrent nested in a folder: content_path sits below the save
        // path, and only save-path-relative resolution (not basename) finds it.
        mkdir($this->root . '/Red Rising', 0o775, true);
        file_put_contents($this->root . '/Red Rising/book.epub', 'EPUB');

        $client = $this->client(new DownloadStatus(
            state: DownloadStatus::STATE_COMPLETE,
            progress: 100.0,
            filePath: self::SAVE_PATH . '/Red Rising/book.epub',
            savePath: self::SAVE_PATH,
        ));

        self::assertSame(
            $this->root . '/Red Rising/book.epub',
            $this->finalizer()->sourceAvailability($this->job(), $client),
        );
    }

    public function testReturnsNullWhenClientReportsTorrentMissing(): void
    {
        // Even with the files still on disk: a torrent the client no longer has
        // cannot vouch for its content path.
        mkdir($this->root . '/Red Rising', 0o775, true);

        $client = $this->client(new DownloadStatus(
            state: DownloadStatus::STATE_MISSING,
            progress: 0.0,
            filePath: self::SAVE_PATH . '/Red Rising',
            savePath: self::SAVE_PATH,
        ));

        self::assertNull($this->finalizer()->sourceAvailability($this->job(), $client));
    }

    public function testReturnsNullWhenFilesAreGoneFromDisk(): void
    {
        // The client still seeds it, but nothing exists at the resolved path
        // (mount gone, or the files were deleted out from under the client).
        $client = $this->client(new DownloadStatus(
            state: DownloadStatus::STATE_SEEDING,
            progress: 100.0,
            filePath: self::SAVE_PATH . '/Red Rising',
            savePath: self::SAVE_PATH,
        ));

        self::assertNull($this->finalizer()->sourceAvailability($this->job(), $client));
    }

    public function testReturnsNullWithoutAClientRef(): void
    {
        // No hash to query by — the client must not even be asked.
        $client = $this->client(status: null);

        $job = $this->job()->setClientRef(null);

        self::assertNull($this->finalizer()->sourceAvailability($job, $client));
        self::assertSame(0, $client->statusQueries);
    }

    public function testFailWithBlockReleaseRecordsABlockForTheJobsBook(): void
    {
        // A junk-content failure (blockRelease: true) must blocklist the job's
        // release for its book — keyed off the job stamp: source/guid plus the
        // magnet and infohash, with the failure message as the reason.
        $book = new Book('grimmory', 'ext-1', 'Red Rising');
        $magnet = 'magnet:?xt=urn:btih:3601266b0873bfc80fd1f782632b38f9a60bf5a1';
        $job = (new DownloadJob('torrent', 'guid-junk', ReleaseCandidate::PROTOCOL_TORRENT, new BookRequest(new User('u'), $book)))
            ->setClientRef('abc123')
            ->setDownloadUrl($magnet);

        $repository = $this->createMock(BlockedReleaseRepository::class);
        $repository->expects(self::once())->method('blockRelease')->with(
            self::identicalTo($book),
            'torrent',
            'guid-junk',
            ReleaseCandidate::PROTOCOL_TORRENT,
            $magnet,
            'abc123',
            'No ebook files found in the completed torrent at /x.',
        );

        $this->finalizer($repository)->fail($job, 'No ebook files found in the completed torrent at /x.', blockRelease: true);

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
        self::assertSame(DownloadJob::STATUS_ERROR, $job->getBookRequest()?->getDeliveryStatus());
    }

    public function testFailWithoutBlockFlagNeverTouchesTheBlocklist(): void
    {
        // Environmental failures (missing mount, unconfigured destination, move
        // errors, poller-detected client problems) must never produce a block.
        $repository = $this->createMock(BlockedReleaseRepository::class);
        $repository->expects(self::never())->method('blockRelease');

        $book = new Book('grimmory', 'ext-1', 'Red Rising');
        $job = new DownloadJob('torrent', 'guid-1', ReleaseCandidate::PROTOCOL_TORRENT, new BookRequest(new User('u'), $book));

        $this->finalizer($repository)->fail($job, 'Move into library failed: disk full');

        self::assertSame(DownloadJob::STATUS_ERROR, $job->getStatus());
    }

    public function testFailWithBlockFlagButNoBookDoesNotBlock(): void
    {
        $repository = $this->createMock(BlockedReleaseRepository::class);
        $repository->expects(self::never())->method('blockRelease');

        $this->finalizer($repository)->fail($this->job(), 'Completed torrent is implausibly small.', blockRelease: true);
    }

    // --- helpers ----------------------------------------------------------

    private function finalizer(?BlockedReleaseRepository $blockedReleases = null): TorrentFinalizer
    {
        // sourceAvailability() touches only the client and the filesystem; the
        // pipeline collaborators are final classes, so hand over uninitialized
        // instances (same pattern as TorrentFulfillmentTest).
        $reflect = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new TorrentFinalizer(
            $reflect(IntegrationRepository::class),
            $reflect(TorrentMover::class),
            $reflect(FileMover::class),
            $reflect(EbookMetadataInjector::class),
            $reflect(AudiobookSidecarWriter::class),
            $reflect(AudiobookTagWriter::class),
            $reflect(FilenameTemplate::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new FulfillmentLog($this->createStub(Connection::class), new NullLogger()),
            new NullLogger(),
            $blockedReleases ?? $reflect(BlockedReleaseRepository::class),
            $this->root,
        );
    }

    private function job(): DownloadJob
    {
        return (new DownloadJob('torrent', 'guid-1', ReleaseCandidate::PROTOCOL_TORRENT))
            ->setClientRef('abc123');
    }

    /** @return DownloadClientInterface&object{statusQueries: int} */
    private function client(?DownloadStatus $status): DownloadClientInterface
    {
        return new class($status) implements DownloadClientInterface {
            public int $statusQueries = 0;

            public function __construct(private readonly ?DownloadStatus $status)
            {
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
                return true;
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
                if ($this->status === null) {
                    throw new \LogicException('getStatus must not be called');
                }

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

    private function rrmdir(string $dir): void
    {
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
