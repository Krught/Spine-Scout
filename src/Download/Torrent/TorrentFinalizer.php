<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Metadata\AudiobookSidecarWriter;
use App\Download\Metadata\AudiobookTagWriter;
use App\Download\Metadata\EbookMetadataInjector;
use App\Entity\DownloadJob;
use App\Message\TriggerGrimmorySidecarImport;
use App\Repository\IntegrationRepository;
use App\Support\EbookFormat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Imports a finished torrent into the library — the finalize step extracted from
 * the torrent poller so a completed job can also be re-imported on demand
 * ({@see \App\MessageHandler\ReimportDownloadJobHandler}): audiobooks get the
 * whole release folder moved (missing-tag fill on the staged copy, Grimmory
 * sidecar, delayed sidecar-import trigger), books get the best ebook file staged,
 * metadata-injected, and moved. Completing a job also runs the operator's
 * remove-on-complete cleanup against the download client.
 */
final class TorrentFinalizer implements TorrentFinalizerInterface
{
    /** Reject obviously-empty payloads (e.g. a stub/metadata-only torrent). */
    private const MIN_SANE_BYTES = 64 * 1024;

    /**
     * @param string $downloadsRoot Where the download client's completed files are read.
     *                              Production always uses the fixed /downloads mount (the
     *                              default); only tests point this at a temp directory.
     */
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly TorrentMover $mover,
        private readonly FileMover $fileMover,
        private readonly EbookMetadataInjector $metadataInjector,
        private readonly AudiobookSidecarWriter $sidecarWriter,
        private readonly AudiobookTagWriter $tagWriter,
        private readonly FilenameTemplate $filenames,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
        private readonly string $downloadsRoot = TorrentClientConfig::DOWNLOADS_MOUNT,
    ) {
    }

    public function finalize(DownloadJob $job, DownloadStatus $status, string $subject, DownloadClientInterface $client): void
    {
        $sourcePath = $this->localSourcePath($status);

        // The completed files must be readable INSIDE this container. If the path
        // doesn't resolve, the /downloads mount is missing or wrong — say so precisely.
        if (!file_exists($sourcePath)) {
            $this->fail($job, sprintf(
                'Expected the completed torrent at "%s" (the download client reported "%s"), but it is not '
                . 'there. Bind-mount your download client\'s completed-downloads folder into the Spine Scout '
                . 'container at %s.',
                $sourcePath,
                (string) $status->filePath,
                TorrentClientConfig::DOWNLOADS_MOUNT,
            ));

            return;
        }

        if ($job->getBookRequest()?->isAudiobook()) {
            $this->finalizeAudiobook($job, $sourcePath, $subject, $client);
        } else {
            $this->finalizeEbook($job, $sourcePath, $subject, $client);
        }
    }

    public function sourceAvailability(DownloadJob $job, DownloadClientInterface $client): ?string
    {
        $hash = $job->getClientRef();
        if ($hash === null || $hash === '') {
            return null;
        }

        $status = $client->getStatus($hash);
        // MISSING: the client confirmed the torrent is gone. UNKNOWN/ERROR: the
        // client couldn't vouch for it — don't claim availability on a guess.
        $gone = [DownloadStatus::STATE_MISSING, DownloadStatus::STATE_UNKNOWN, DownloadStatus::STATE_ERROR];
        if (\in_array($status->state, $gone, true) || $status->filePath === null) {
            return null;
        }

        $sourcePath = $this->localSourcePath($status);

        return file_exists($sourcePath) ? $sourcePath : null;
    }

    public function fail(DownloadJob $job, string $message): void
    {
        $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($message);
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_ERROR);
        $this->em->flush();
        $this->log->error('Torrent download failed: ' . $message, $job->getBookRequest()?->getBook()->getTitle());
        $this->logger->warning('Torrent job failed', ['job' => $job->getId(), 'error' => $message]);
    }

    /**
     * Resolve the client-reported content path to where this container reads it,
     * under the fixed /downloads mount using the client's save path (see
     * TorrentClientConfig::localContentPath) — the bind mount covers that save
     * directory, so content_path must be resolved relative to it, not just by
     * basename (which drops any intermediate folder for e.g. single-file torrents).
     */
    private function localSourcePath(DownloadStatus $status): string
    {
        $path = TorrentClientConfig::localContentPath((string) $status->filePath, $status->savePath);

        // Test seam only: in production $downloadsRoot IS the fixed mount, so this
        // re-prefixing never runs and the resolution above stands unchanged.
        if ($this->downloadsRoot !== TorrentClientConfig::DOWNLOADS_MOUNT) {
            $path = $this->downloadsRoot . substr($path, \strlen(TorrentClientConfig::DOWNLOADS_MOUNT));
        }

        return $path;
    }

    private function finalizeAudiobook(DownloadJob $job, string $sourcePath, string $subject, DownloadClientInterface $client): void
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
        $book = $job->getBookRequest()?->getBook();

        // Fill missing embedded tags on the STAGED copy, before the album becomes
        // visible in the library. The try/catch is mandatory even though the writer
        // shouldn't throw: a throwing $beforeFinalize callback aborts the whole move,
        // and a tagging surprise must never cost an otherwise-good import.
        $beforeFinalize = null;
        if ($config->writeAudioTags && $book !== null) {
            $beforeFinalize = function (string $stageDir) use ($book, $job): void {
                try {
                    $this->tagWriter->fillMissingTags($stageDir, $book);
                } catch (\Throwable $e) {
                    $this->logger->warning('Audio tag fill failed; importing as-is', ['job' => $job->getId(), 'error' => $e->getMessage()]);
                }
            };
        }

        try {
            $finalDir = $this->mover->move($sourcePath, $destDir, $folderName, (string) $job->getId(), $beforeFinalize);
        } catch (\Throwable $e) {
            $this->fail($job, 'Move into library failed: ' . $e->getMessage());

            return;
        }

        // Drop a Grimmory-importable metadata sidecar (and cover) for the imported
        // album. The whole release folder (companion files included) was moved, and
        // the writer resolves placement itself: beside the folder for a folder-based
        // audiobook, next to the audio file for a single-file one. Best-effort.
        if ($config->writeGrimmorySidecars && $book !== null) {
            $this->sidecarWriter->writeForAlbum($finalDir, $book);
        }

        $this->complete($job, $finalDir, sprintf('Audiobook moved to library: %s (%d file(s))', basename($finalDir), \count($audioFiles)), $subject, $client);

        // Ask Grimmory to import the sidecar — delayed 5 minutes, because Grimmory's
        // file watcher must index the new book before an import-all can match its
        // sidecar. The handler no-ops safely when native credentials aren't configured.
        if ($config->writeGrimmorySidecars) {
            $this->bus->dispatch(new TriggerGrimmorySidecarImport(), [new DelayStamp(300_000)]);
        }
    }

    private function finalizeEbook(DownloadJob $job, string $sourcePath, string $subject, DownloadClientInterface $client): void
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
            $this->fail($job, 'No ebook library / watch folder configured in Settings → Ebooks.');

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

        $this->complete($job, $finalPath, 'Book moved to library: ' . basename($finalPath), $subject, $client);
    }

    private function complete(DownloadJob $job, string $finalPath, string $logMessage, string $subject, DownloadClientInterface $client): void
    {
        $job->setFilePath($finalPath)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100)
            ->setStatusMessage(null);
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_COMPLETE);
        $this->em->flush();

        $this->log->info($logMessage, $subject);
        $this->logger->info('Torrent complete', ['job' => $job->getId(), 'path' => $finalPath]);

        $this->cleanupTorrent($job, $client);
    }

    /**
     * The library now holds its own copy of the import, so unless the operator opted to
     * keep seeding, remove the finished torrent from the download client and delete its
     * original files (qBittorrent's torrents/delete with deleteFiles=true). A failure
     * here is non-fatal — the import already succeeded — so it only logs.
     */
    private function cleanupTorrent(DownloadJob $job, DownloadClientInterface $client): void
    {
        if (!$this->integrations->getTorrentClientConfig()->removeOnComplete) {
            return;
        }

        $hash = (string) $job->getClientRef();
        if ($hash === '') {
            return;
        }

        if ($client->cancel($hash, true)) {
            $this->logger->info('Torrent removed from download client after import', ['job' => $job->getId(), 'hash' => $hash]);
        } else {
            $this->logger->warning('Failed to remove torrent from download client after import', ['job' => $job->getId(), 'hash' => $hash]);
        }
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
}
