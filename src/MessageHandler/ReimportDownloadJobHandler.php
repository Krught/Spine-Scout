<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\FulfillmentLog;
use App\Download\Torrent\TorrentFinalizerInterface;
use App\Entity\DownloadJob;
use App\Message\ReimportDownloadJob;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Re-runs the import pipeline for ONE completed torrent download whose raw files
 * are still in the download client (still seeding, or remove-on-complete off):
 * queries the client for the torrent's current status and hands the job back to
 * {@see TorrentFinalizerInterface} — the exact pipeline the poller ran the first
 * time. The finalizer moves files collision-safely (a " (n)" suffix on the final
 * name), so a reimport lands a fresh copy beside the previous import instead of
 * clobbering it.
 *
 * Every precondition is a skip (log, no error), never a throw, so a stale or
 * redelivered message is harmless. Note: completing the reimport re-runs the
 * operator's remove-on-complete cleanup — with that policy on, the reimported
 * torrent is removed from the client afterwards, by design.
 */
#[AsMessageHandler]
final class ReimportDownloadJobHandler
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly TorrentFinalizerInterface $finalizer,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReimportDownloadJob $message): void
    {
        $job = $this->em->find(DownloadJob::class, $message->downloadJobId);
        if ($job === null) {
            return;
        }

        if ($job->getStatus() !== DownloadJob::STATUS_COMPLETE) {
            $this->logger->info('Reimport skipped: job is not complete', ['job' => $job->getId(), 'status' => $job->getStatus()]);

            return;
        }

        if ($job->getProtocol() !== ReleaseCandidate::PROTOCOL_TORRENT) {
            $this->logger->info('Reimport skipped: not a torrent job', ['job' => $job->getId(), 'protocol' => $job->getProtocol()]);

            return;
        }

        $client = $this->clientFor(ReleaseCandidate::PROTOCOL_TORRENT);
        if ($client === null) {
            $this->logger->info('Reimport skipped: no torrent download client configured', ['job' => $job->getId()]);

            return;
        }

        if ($this->finalizer->sourceAvailability($job, $client) === null) {
            $this->logger->info('Reimport skipped: torrent files are no longer available in the download client', ['job' => $job->getId()]);

            return;
        }

        $subject = $job->getBookRequest()?->getBook()->getTitle() ?? $job->getSourceId();
        $this->log->info('Reimporting ' . $subject . ' from the download client', $subject);

        // finalize() moves collision-safely (" (n)" suffix on the final name), so
        // this lands a fresh copy without clobbering the previous import.
        $status = $client->getStatus((string) $job->getClientRef());
        $this->finalizer->finalize($job, $status, $subject, $client);
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
}
