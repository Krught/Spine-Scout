<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Progress\FulfillmentDownloadProgressReporter;
use App\Entity\DownloadJob;
use App\Message\ProcessDownloadJob;
use App\Search\SearchSettingsProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Drives one DownloadJob to completion: claim it under a row lock (so duplicate
 * delivery / parallel workers don't double-process), fetch the file trying each
 * candidate link in order, move it into the configured output folder, and keep
 * the job + request delivery status in sync. On total failure the job is marked
 * errored and the request stays APPROVED for a manual retry.
 */
#[AsMessageHandler]
final class ProcessDownloadJobHandler
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly SearchSettingsProvider $settings,
        private readonly FileMover $mover,
        private readonly FilenameTemplate $filenames,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessDownloadJob $message): void
    {
        $job = $this->claim($message->downloadJobId);
        if ($job === null) {
            return;
        }

        $links = $job->getCandidateLinks();
        if ($links === [] && $job->getDownloadUrl() !== null) {
            $links = [$job->getDownloadUrl()];
        }
        if ($links === []) {
            $this->fail($job, 'No download links available.');

            return;
        }

        $client = $this->clientFor($job->getProtocol());
        if ($client === null) {
            $this->fail($job, "No download client for protocol '{$job->getProtocol()}'.");

            return;
        }

        $subject = $this->baseName($job);
        $this->log->info(sprintf('Downloading via %d link(s)…', \count($links)), $subject);

        $staged = null;
        $lastError = 'unknown error';
        foreach ($links as $i => $url) {
            // Per-link reporter: the client and bypassers emit their stages
            // (opening the browser, clearing the challenge, finding the partner
            // link, streaming the file) into the activity monitor under this
            // "Link i/N" prefix, so a failure shows exactly where it stopped.
            $progress = new FulfillmentDownloadProgressReporter($this->log, $subject, sprintf('Link %d/%d', $i + 1, \count($links)));
            try {
                $downloadId = $client->addDownload($url, $subject, ['progress' => $progress]);
                $status = $client->getStatus($downloadId);
                if ($status->isComplete() && $status->filePath !== null) {
                    $staged = $status->filePath;
                    break;
                }
                $lastError = $status->message ?? 'download did not complete';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->log->warn(sprintf('Link %d/%d failed: %s', $i + 1, \count($links), $lastError), $subject);
                $this->logger->warning('Download link failed', ['job' => $job->getId(), 'url' => $url, 'error' => $lastError]);
            }
        }

        if ($staged === null) {
            $this->fail($job, "All download links failed: {$lastError}");

            return;
        }

        $config = $this->settings->getDirectDownloadConfig();
        if (trim($config->outputDirectory) === '') {
            @unlink($staged);
            $this->fail($job, 'No output / watch folder configured in Settings → Direct downloads.');

            return;
        }

        $filename = $this->filenames->render($config->filenameTemplate, $this->tokens($job), $job->getFormat());

        try {
            $finalPath = $this->mover->move($staged, $config->outputDirectory, $filename);
        } catch (\Throwable $e) {
            @unlink($staged);
            $this->fail($job, 'Move to output folder failed: ' . $e->getMessage());

            return;
        }

        $job->setFilePath($finalPath)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100)
            ->setStatusMessage(null);
        $this->mirrorDelivery($job);
        $this->em->flush();

        $this->log->info('Downloaded → ' . basename($finalPath) . ' (awaiting library import)', $subject);
        $this->logger->info('Download complete', ['job' => $job->getId(), 'path' => $finalPath]);
    }

    /**
     * Claim the job under a pessimistic write lock and flip it to downloading.
     * Returns null when the job is gone or already past the queued state (another
     * worker has it, or the message was redelivered).
     */
    private function claim(int $jobId): ?DownloadJob
    {
        return $this->em->wrapInTransaction(function () use ($jobId): ?DownloadJob {
            $job = $this->em->find(DownloadJob::class, $jobId, LockMode::PESSIMISTIC_WRITE);
            if ($job === null || $job->getStatus() !== DownloadJob::STATUS_QUEUED) {
                return null;
            }
            $job->setStatus(DownloadJob::STATUS_DOWNLOADING)->setProgress(0);
            $this->mirrorDelivery($job);

            return $job;
        });
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

    private function fail(DownloadJob $job, string $message): void
    {
        $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($message);
        $this->mirrorDelivery($job);
        $this->em->flush();
        $this->log->error('Download failed: ' . $message, $this->baseName($job));
        $this->logger->warning('Download job failed', ['job' => $job->getId(), 'error' => $message]);
    }

    private function mirrorDelivery(DownloadJob $job): void
    {
        $job->getBookRequest()?->setDeliveryStatus($job->getStatus());
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
        $isbn = $book?->getIsbn() ?? ($book?->getIsbns()[0] ?? null);

        return [
            'author' => $book?->getAuthor(),
            'title'  => $book?->getTitle() ?? $job->getSourceId(),
            'year'   => $year,
            'isbn'   => $isbn,
            'format' => $job->getFormat(),
        ];
    }

    private function baseName(DownloadJob $job): string
    {
        return $job->getBookRequest()?->getBook()->getTitle() ?? $job->getSourceId();
    }
}
