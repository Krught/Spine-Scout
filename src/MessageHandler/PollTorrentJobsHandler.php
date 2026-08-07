<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Download\Torrent\TorrentFinalizerInterface;
use App\Entity\DownloadJob;
use App\Message\PollTorrentJobs;
use App\Repository\DownloadJobRepository;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Advances every in-flight torrent job each tick by querying the download client:
 * a still-downloading torrent updates progress; a finished one (seeding, with a
 * content path) is handed to {@see TorrentFinalizerInterface} for the sanity checks
 * and the move into the library (audiobook → audio destination, book → ebook
 * library); a torrent the client no longer has (removed manually) errors the job so
 * the request re-attempts. This is the only automatic finalizer of an async torrent
 * — the on-demand reimport path (ReimportDownloadJobHandler) shares the same
 * pipeline.
 */
#[AsMessageHandler]
final class PollTorrentJobsHandler
{
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
        private readonly TorrentFinalizerInterface $finalizer,
        private readonly EntityManagerInterface $em,
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
                $this->finalizer->fail($job, 'Download client error: ' . ($status->message ?? 'unknown'));
                continue;
            }

            // The client couldn't be queried at all (transport/auth failure). This is
            // NOT evidence the torrent was removed — it's just as likely a transient
            // network blip, a qBittorrent restart, or a login lockout. Leave the job
            // active and retry next tick; only a confirmed STATE_MISSING response
            // below counts as removal.
            if ($status->state === DownloadStatus::STATE_UNKNOWN) {
                $this->logger->warning('Download client query failed while polling torrent job; will retry', [
                    'job'   => $job->getId(),
                    'error' => $status->message,
                ]);
                continue;
            }

            // The client confirmed this torrent isn't there. Just after the add it may
            // not be registered yet (tolerate briefly); past the grace window it means
            // the torrent was actually removed from the client — fail so the request
            // re-attempts.
            if ($status->state === DownloadStatus::STATE_MISSING) {
                if ($this->ageSeconds($job) > self::REMOVAL_GRACE_SECONDS) {
                    $this->finalizer->fail($job, 'Torrent is no longer in the download client (removed); will search again.');
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

            $this->finalizer->finalize($job, $status, $subject, $client);
        }
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
}
