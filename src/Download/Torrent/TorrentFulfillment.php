<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Download\Client\DownloadClientInterface;
use App\Download\FulfillmentLog;
use App\Entity\DownloadJob;
use App\Integration\Prowlarr\ProwlarrClient;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\TorrentMatchScorer;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Shared torrent search-and-add step used by both the audiobook pipeline and the
 * book "Torrent" source: search the configured indexers, rank by the weighted
 * policy, and hand the best magnet to the download client — stamping the job with
 * the torrent hash and flipping it to DOWNLOADING. The job is then async; the
 * torrent poller (PollTorrentJobs) finalizes it.
 *
 * Does NOT flush — the caller owns the entity manager / transaction.
 */
final class TorrentFulfillment implements TorrentFulfillmentInterface
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly ProwlarrClient $indexers,
        private readonly TorrentMatchScorer $scorer,
        private readonly IntegrationRepository $integrations,
        private readonly FulfillmentLog $log,
    ) {
    }

    /** True when both an indexer manager and a download client are configured. */
    public function isAvailable(): bool
    {
        return $this->indexers->isConfigured() && $this->client() !== null;
    }

    /**
     * Search indexers for the plan, add the best torrent to the download client, and
     * stamp the job (protocol=torrent, client_ref, DOWNLOADING). Returns false when
     * nothing is configured or no release clears the criteria — the caller can then
     * fall through to another source. Throws when the client rejects the add.
     *
     * @throws \RuntimeException on download-client failure
     */
    public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }

        $candidates = $this->indexers->search($plan);
        if ($candidates === []) {
            return false;
        }
        $ranked = $this->scorer->rank($candidates, $plan, $this->integrations->getProwlarrConfig()->matchPolicy());
        if ($ranked === []) {
            return false;
        }
        $pick = $ranked[0];
        $magnet = (string) $pick->downloadUrl;

        // Stamp the winning release. Clamp the indexer guid to the source_id column
        // width (the full magnet lives in download_url / candidate_links, both TEXT).
        $job->setSource('torrent')
            ->setSourceId(mb_substr($pick->sourceId, 0, 255))
            ->setProtocol(ReleaseCandidate::PROTOCOL_TORRENT)
            ->setFormat($pick->format !== null ? mb_substr($pick->format, 0, 16) : null)
            ->setSizeBytes($pick->sizeBytes)
            ->setCandidateLinks([$magnet])
            ->setDownloadUrl($magnet);

        $hash = $client->addDownload($magnet, $subject);

        $job->setClientRef($hash)
            ->setStatus(DownloadJob::STATUS_DOWNLOADING)
            ->setProgress(0)
            ->setStatusMessage('Downloading via download client…');
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_DOWNLOADING);

        $this->log->info(
            sprintf('Added to download client: %s (%d seeders)', $pick->indexer ?? 'torrent', $pick->seeders ?? 0),
            $subject,
        );

        return true;
    }

    private function client(): ?DownloadClientInterface
    {
        foreach ($this->downloadClients as $client) {
            if ($client->getProtocol() === ReleaseCandidate::PROTOCOL_TORRENT && $client->isConfigured()) {
                return $client;
            }
        }

        return null;
    }
}
