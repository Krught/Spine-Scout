<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Torrent\TorrentClientConfig;
use App\Download\Torrent\TorrentMover;
use App\Entity\DownloadJob;
use App\Message\PollTorrentJobs;
use App\Repository\DownloadJobRepository;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Support\EbookFormat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Advances every in-flight torrent job each tick by querying the download client:
 * a still-downloading torrent updates progress; a finished one (seeding, with a
 * content path) passes the automatic sanity checks and is moved into the library
 * (audiobook → audio destination, book → ebook library); a torrent the client no
 * longer has (removed manually) errors the job so the request re-attempts. This is
 * the only thing that finalizes an async torrent — books and audiobooks alike.
 */
#[AsMessageHandler]
final class PollTorrentJobsHandler
{
    /** Reject obviously-empty payloads (e.g. a stub/metadata-only torrent). */
    private const MIN_SANE_BYTES = 64 * 1024;

    /**
     * Grace after a job is created before a "not in the client" reading is treated
     * as a removal rather than the brief post-add registration lag.
     */
    private const REMOVAL_GRACE_SECONDS = 120;

    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly DownloadJobRepository $jobs,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly IntegrationRepository $integrations,
        private readonly TorrentMover $mover,
        private readonly FileMover $fileMover,
        private readonly EbookMetadataInjector $metadataInjector,
        private readonly FilenameTemplate $filenames,
        private readonly EntityManagerInterface $em,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollTorrentJobs $message): void
    {
        $active = $this->jobs->activeTorrentJobs();
        if ($active === []) {
            return;
        }

        $client = $this->clientFor(ReleaseCandidate::PROTOCOL_TORRENT);
        if ($client === null) {
            return; // No download client configured — leave jobs for the next tick.
        }

        foreach ($active as $job) {
            $hash = $job->getClientRef();
            if ($hash === null || $hash === '') {
                continue; // Not yet handed to the client (still resolving).
            }

            $status = $client->getStatus($hash);
            $subject = $job->getBookRequest()?->getBook()->getTitle() ?? $job->getSourceId();

            if ($status->state === DownloadStatus::STATE_ERROR) {
                $this->fail($job, 'Download client error: ' . ($status->message ?? 'unknown'));
                continue;
            }

            // The client doesn't have this torrent. Just after the add it may not be
            // registered yet (tolerate briefly); past the grace window it means the
            // torrent was removed from the client — fail so the request re-attempts.
            if ($status->state === DownloadStatus::STATE_UNKNOWN) {
                if ($this->ageSeconds($job) > self::REMOVAL_GRACE_SECONDS) {
                    $this->fail($job, 'Torrent is no longer in the download client (removed); will search again.');
                }
                continue;
            }

            $ready = $status->isComplete() || $status->state === DownloadStatus::STATE_SEEDING;
            if (!$ready || $status->filePath === null) {
                // Still downloading — record progress and move on.
                $job->setStatus(DownloadJob::STATUS_DOWNLOADING)
                    ->setProgress((int) round($status->progress))
                    ->setStatusMessage($status->message);
                $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_DOWNLOADING);
                $this->em->flush();
                continue;
            }

            $this->finalize($job, $status, $subject);
        }
    }

    private function finalize(DownloadJob $job, DownloadStatus $status, string $subject): void
    {
        $rawPath = (string) $status->filePath;
        // Resolve under the fixed /downloads mount by basename — the client's own save
        // path doesn't matter, only that its completed-downloads folder is mounted there.
        $sourcePath = TorrentClientConfig::localContentPath($rawPath);

        // The completed files must be readable INSIDE this container. If the path
        // doesn't resolve, the /downloads mount is missing or wrong — say so precisely.
        if (!file_exists($sourcePath)) {
            $this->fail($job, sprintf(
                'Expected the completed torrent at "%s" (the download client reported "%s"), but it is not '
                . 'there. Bind-mount your download client\'s completed-downloads folder into the Spine Scout '
                . 'container at %s.',
                $sourcePath,
                $rawPath,
                TorrentClientConfig::DOWNLOADS_MOUNT,
            ));

            return;
        }

        if ($job->getBookRequest()?->isAudiobook()) {
            $this->finalizeAudiobook($job, $sourcePath, $subject);
        } else {
            $this->finalizeEbook($job, $sourcePath, $subject);
        }
    }

    private function finalizeAudiobook(DownloadJob $job, string $sourcePath, string $subject): void
    {
        $config = $this->integrations->getTorrentClientConfig();

        $audioFiles = TorrentMover::audioFiles($sourcePath);
        if ($audioFiles === []) {
            $this->fail($job, 'No audio files found in the completed torrent at ' . $sourcePath . '.');

            return;
        }
        if ($this->totalBytes($audioFiles) < self::MIN_SANE_BYTES) {
            $this->fail($job, 'Completed torrent is implausibly small.');

            return;
        }

        $destDir = $config->useEbookLibraryDir
            ? $this->integrations->getDirectDownloadConfig()->outputDirectory
            : $config->audioOutputDirectory;
        if (trim($destDir) === '') {
            $this->fail($job, 'No audiobook destination directory configured.');

            return;
        }

        $folderName = $this->filenames->render($config->filenameTemplate, $this->tokens($job), null);

        try {
            $finalDir = $this->mover->move($sourcePath, $destDir, $folderName, (string) $job->getId());
        } catch (\Throwable $e) {
            $this->fail($job, 'Move into library failed: ' . $e->getMessage());

            return;
        }

        $this->complete($job, $finalDir, sprintf('Audiobook moved to library: %s (%d file(s))', basename($finalDir), \count($audioFiles)), $subject);
    }

    private function finalizeEbook(DownloadJob $job, string $sourcePath, string $subject): void
    {
        $ddConfig = $this->integrations->getDirectDownloadConfig();

        $ebookFiles = TorrentMover::filesMatching($sourcePath, static fn (string $p): bool => EbookFormat::isEbook(pathinfo($p, PATHINFO_EXTENSION)));
        if ($ebookFiles === []) {
            $this->fail($job, 'No ebook files found in the completed torrent at ' . $sourcePath . '.');

            return;
        }
        // Prefer the best ebook format, then the largest file of that format.
        usort($ebookFiles, static function (string $a, string $b): int {
            $ra = EbookFormat::rank((string) pathinfo($a, PATHINFO_EXTENSION));
            $rb = EbookFormat::rank((string) pathinfo($b, PATHINFO_EXTENSION));

            return $ra <=> $rb ?: ((int) @filesize($b) <=> (int) @filesize($a));
        });
        $best = $ebookFiles[0];
        if ((int) (@filesize($best) ?: 0) < self::MIN_SANE_BYTES) {
            $this->fail($job, 'Completed torrent is implausibly small.');

            return;
        }

        $outputDir = $ddConfig->outputDirectory;
        if (trim($outputDir) === '') {
            $this->fail($job, 'No output / watch folder configured in Settings → Direct downloads.');

            return;
        }

        $ext = strtolower((string) pathinfo($best, PATHINFO_EXTENSION));
        $job->setFormat($ext !== '' ? mb_substr($ext, 0, 16) : null);
        $filename = $this->filenames->render($ddConfig->filenameTemplate, $this->tokens($job) + ['format' => $ext], $ext);

        // Copy out of the (seeding) torrent folder into a staging file, rewrite
        // embedded metadata, then move it into the library.
        $staged = $this->stageCopy($best);
        if ($staged === null) {
            $this->fail($job, 'Could not stage the ebook file for import.');

            return;
        }
        $book = $job->getBookRequest()?->getBook();
        if ($book !== null) {
            $this->metadataInjector->inject($staged, $book, $ext);
        }

        try {
            $finalPath = $this->fileMover->move($staged, $outputDir, $filename);
        } catch (\Throwable $e) {
            @unlink($staged);
            $this->fail($job, 'Move into library failed: ' . $e->getMessage());

            return;
        }

        $this->complete($job, $finalPath, 'Book moved to library: ' . basename($finalPath), $subject);
    }

    private function complete(DownloadJob $job, string $finalPath, string $logMessage, string $subject): void
    {
        $job->setFilePath($finalPath)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100)
            ->setStatusMessage(null);
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_COMPLETE);
        $this->em->flush();

        $this->log->info($logMessage, $subject);
        $this->logger->info('Torrent complete', ['job' => $job->getId(), 'path' => $finalPath]);
    }

    /** Copy a file into a temp staging path so the original keeps seeding. */
    private function stageCopy(string $source): ?string
    {
        $staged = sys_get_temp_dir() . '/spinescout-' . bin2hex(random_bytes(6)) . '-' . basename($source);
        if (!@copy($source, $staged)) {
            return null;
        }

        return $staged;
    }

    /** @param list<string> $files */
    private function totalBytes(array $files): int
    {
        $total = 0;
        foreach ($files as $f) {
            $total += (int) (@filesize($f) ?: 0);
        }

        return $total;
    }

    private function ageSeconds(DownloadJob $job): int
    {
        return (new \DateTimeImmutable())->getTimestamp() - $job->getCreatedAt()->getTimestamp();
    }

    private function clientFor(string $protocol): ?DownloadClientInterface
    {
        foreach ($this->downloadClients as $client) {
            if ($client->getProtocol() === $protocol && $client->isConfigured()) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    private function tokens(DownloadJob $job): array
    {
        $book = $job->getBookRequest()?->getBook();
        $year = null;
        if ($book !== null && $book->getPublishedDate() !== null && preg_match('/(\d{4})/', $book->getPublishedDate(), $m)) {
            $year = $m[1];
        }

        return [
            'author' => $book?->getAuthor(),
            'title'  => $book?->getTitle() ?? $job->getSourceId(),
            'year'   => $year,
            'isbn'   => $book?->getIsbn() ?? ($book?->getIsbns()[0] ?? null),
        ];
    }

    private function fail(DownloadJob $job, string $message): void
    {
        $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($message);
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_ERROR);
        $this->em->flush();
        $this->log->error('Torrent download failed: ' . $message, $job->getBookRequest()?->getBook()->getTitle());
        $this->logger->warning('Torrent job failed', ['job' => $job->getId(), 'error' => $message]);
    }
}
