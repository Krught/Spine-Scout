<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\TorrentClientSettings;
use App\Download\Metadata\AudiobookSidecarWriter;
use App\Entity\DownloadJob;
use App\Message\RewriteAudiobookSidecar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Re-emits the Grimmory metadata/cover sidecar for one completed audiobook's album
 * folder, reusing the same {@see AudiobookSidecarWriter} the original import ran —
 * the writer resolves placement itself (beside the folder for folder-based
 * audiobooks, next to the audio file for single-file ones). The album folder is
 * recovered from the job's stored file path — for an audiobook job that path IS the
 * album folder ({@see \App\Download\Torrent\TorrentFinalizer::finalizeAudiobook()}).
 *
 * Skips (logs, no error) when the operator disabled Grimmory sidecars, and jobs
 * that aren't a completed audiobook with an on-disk folder, so a stale or
 * redelivered message can never throw.
 */
#[AsMessageHandler]
final class RewriteAudiobookSidecarHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TorrentClientSettings $integrations,
        private readonly AudiobookSidecarWriter $sidecarWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RewriteAudiobookSidecar $message): void
    {
        if (!$this->integrations->getTorrentClientConfig()->writeGrimmorySidecars) {
            $this->logger->info('Sidecar rewrite skipped: Grimmory sidecars are disabled', ['job' => $message->downloadJobId]);

            return;
        }

        $job = $this->em->find(DownloadJob::class, $message->downloadJobId);
        if ($job === null) {
            return;
        }

        $request = $job->getBookRequest();
        $path = $job->getFilePath();
        if (
            $request === null
            || !$request->isAudiobook()
            || $job->getStatus() !== DownloadJob::STATUS_COMPLETE
            || $path === null
            || $path === ''
        ) {
            $this->logger->info('Sidecar rewrite skipped: not a completed audiobook with a path', ['job' => $job->getId()]);

            return;
        }
        if (!is_dir($path)) {
            $this->logger->warning('Sidecar rewrite skipped: album folder is gone', ['job' => $job->getId(), 'path' => $path]);

            return;
        }

        $this->sidecarWriter->writeForAlbum($path, $request->getBook());
        $this->logger->info('Audiobook sidecar rewritten', ['job' => $job->getId(), 'path' => $path]);
    }
}
