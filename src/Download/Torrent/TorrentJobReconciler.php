<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\Client\DownloadStatus;
use App\Entity\DownloadJob;
use App\Repository\DownloadJobRepository;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Re-links torrent jobs the poller gave up on (STATUS_ERROR) back to a live
 * torrent still sitting in the download client. Before the STATE_MISSING /
 * STATE_UNKNOWN split (see QbittorrentDownloadClient::getStatus), a transient
 * query failure was indistinguishable from a real removal, so the poller could
 * fail a job whose torrent was still downloading (or already finished) in
 * qBittorrent — orphaning it. The client hash (clientRef) survives on the
 * errored row, so this re-queries the client by hash and, if the torrent is
 * still there, puts the job back into an active state for the poller to pick
 * up and finalize normally (including moving already-complete files into the
 * library) — no need to remove/re-add the torrent.
 *
 * Shared by the manual `spinescout:torrents:reconcile` command and the
 * scheduled ReconcileTorrentJobsHandler, so both run identical logic.
 */
final class TorrentJobReconciler
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly DownloadJobRepository $jobs,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function reconcile(bool $apply): TorrentReconciliationResult
    {
        $client = $this->clientFor(ReleaseCandidate::PROTOCOL_TORRENT);
        if ($client === null) {
            return new TorrentReconciliationResult(hasClient: false, checked: 0, reconciled: 0, skipped: 0, gone: 0, lines: []);
        }

        $jobs = $this->jobs->erroredTorrentJobsWithClientRef();
        $lines = [];
        $reconciled = 0;
        $skipped = 0;
        $gone = 0;

        foreach ($jobs as $job) {
            $hash = (string) $job->getClientRef();
            $title = $job->getBookRequest()?->getBook()->getTitle() ?? $job->getSourceId();
            $status = $client->getStatus($hash);

            if ($status->state === DownloadStatus::STATE_MISSING || $status->state === DownloadStatus::STATE_ERROR) {
                $lines[] = sprintf('[gone] %s — %s (%s)', substr($hash, 0, 12), $title, $status->message ?? $status->state);
                ++$gone;
                continue;
            }
            if ($status->state === DownloadStatus::STATE_UNKNOWN) {
                $lines[] = sprintf('[??? ] %s — %s — query failed, try again later (%s)', substr($hash, 0, 12), $title, $status->message ?? '');
                ++$skipped;
                continue;
            }

            $request = $job->getBookRequest();
            if ($request !== null && $this->jobs->hasActiveJobForRequest($request)) {
                $lines[] = sprintf('[skip] %s — %s — another job is already active for this request; resolve manually.', substr($hash, 0, 12), $title);
                ++$skipped;
                continue;
            }

            $lines[] = sprintf(
                '[%s] %s — %s — still in the client (%s, %d%%)%s',
                $apply ? 'fix ' : 'plan',
                substr($hash, 0, 12),
                $title,
                $status->state,
                (int) round($status->progress),
                $apply ? '' : ' — pass --apply to reconcile',
            );

            if ($apply) {
                $job->setStatus(DownloadJob::STATUS_DOWNLOADING)
                    ->setProgress((int) round($status->progress))
                    ->setStatusMessage(null);
                $request?->setDeliveryStatus(DownloadJob::STATUS_DOWNLOADING);
            }
            ++$reconciled;
        }

        if ($apply && $reconciled > 0) {
            $this->em->flush();
        }

        return new TorrentReconciliationResult(
            hasClient: true,
            checked: count($jobs),
            reconciled: $reconciled,
            skipped: $skipped,
            gone: $gone,
            lines: $lines,
        );
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
