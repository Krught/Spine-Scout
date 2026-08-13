<?php

declare(strict_types=1);

namespace App\Download\Mam;

use App\Download\Client\DownloadClientInterface;
use App\Download\FulfillmentLog;
use App\Entity\DownloadJob;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;
use App\Repository\BlockedReleaseRepository;
use App\Repository\IntegrationRepository;
use App\Search\Mam\MamCandidateMapper;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use App\Search\Torrent\TorrentMatchPolicy;
use App\Search\Torrent\TorrentMatchScorer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * MyAnonamouse search-and-add step, the MAM twin of TorrentFulfillment: search
 * MAM directly (no Prowlarr), rank by the weighted policy, optionally spend a
 * personal-freeleech wedge, then hand the fetched .torrent bytes to the torrent
 * download client — stamping the job and flipping it to DOWNLOADING. The job is
 * then async; the torrent poller (PollTorrentJobs) finalizes it off protocol +
 * clientRef, so MAM jobs ride the existing pipeline unchanged.
 *
 * Deliberately a standalone final class, NOT a TorrentFulfillmentInterface
 * implementation — a second implementation would break that interface's
 * auto-alias to TorrentFulfillment, so callers inject this concrete class.
 *
 * Does NOT flush — the caller owns the entity manager / transaction.
 */
final class MamFulfillment
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly MyAnonamouseClient $mam,
        private readonly MyAnonamouseSettingsProvider $settings,
        private readonly TorrentMatchScorer $scorer,
        private readonly IntegrationRepository $integrations,
        private readonly FulfillmentLog $log,
        private readonly BlockedReleaseRepository $blockedReleases,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** True when the MAM integration is usable and a torrent download client is configured. */
    public function isAvailable(): bool
    {
        return $this->mam->isConfigured()
            && $this->settings->getMyAnonamouseConfig()->enabled
            && $this->client() !== null;
    }

    /**
     * Search MAM for the plan, add the best release to the download client, and
     * stamp the job (source=mam, protocol=torrent, client_ref, DOWNLOADING).
     * Returns false when nothing is configured or no release clears the criteria —
     * the caller can then fall through to another source. Whether a wedge is spent
     * on the winner is decided here via MyAnonamouseConfig::wedgeDecision().
     *
     * @throws \RuntimeException on torrent-file or download-client failure
     */
    public function tryFulfill(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }

        $releases = $this->mam->searchReleases($plan, ProwlarrConfig::METHOD_CATEGORIES);
        if ($releases === []) {
            return false;
        }

        $config = $this->settings->getMyAnonamouseConfig();
        $candidates = MamCandidateMapper::mapAll($releases, $config->baseUrl);
        $candidates = $this->withoutBlocked($candidates, $plan, $subject);
        if ($candidates === []) {
            return false;
        }
        $ranked = $this->scorer->rank($candidates, $plan, $this->policyFor($plan));
        if ($ranked === []) {
            return false;
        }

        $best = $ranked[0];
        $useWedge = $config->wedgeDecision($best->sizeBytes, $this->alreadyFreeForUser($best));

        return $this->grab($job, $best, $subject, $useWedge);
    }

    /**
     * Stamp $job with an already-chosen MAM release and hand its .torrent file to
     * the torrent download client. This is the "grab" half of tryFulfill, split out
     * so the interactive search UI can send a user-picked candidate (and wedge
     * choice) down the same path.
     *
     * When $useWedge is set and the release is not already free for the user, a
     * personal-freeleech wedge is spent first; a refused spend is non-fatal — the
     * grab proceeds at normal ratio cost with a warning in the fulfillment log.
     *
     * Returns false when no torrent download client is configured; throws when MAM
     * won't serve the .torrent file or the client rejects the add. Does NOT flush —
     * the caller owns the transaction.
     *
     * @throws \RuntimeException on torrent-file or download-client failure
     */
    public function grab(DownloadJob $job, ReleaseCandidate $candidate, string $subject, bool $useWedge): bool
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }

        $mamExtra = is_array($candidate->extra['mam'] ?? null) ? $candidate->extra['mam'] : [];
        $torrentId = is_numeric($mamExtra['torrentId'] ?? null) ? (int) $mamExtra['torrentId'] : 0;

        if ($useWedge && !$this->alreadyFreeForUser($candidate)) {
            if ($torrentId > 0 && $this->mam->spendWedge($torrentId)) {
                $this->log->info(sprintf('Spent a freeleech wedge on MyAnonamouse torrent %d', $torrentId), $subject);
            } else {
                // Non-fatal by design: the grab still happens, it just costs ratio.
                $this->log->warn('Freeleech wedge spend was refused — grabbing at normal ratio cost', $subject);
                $this->logger->warning('MyAnonamouse wedge spend failed; continuing with the grab', [
                    'torrentId' => $torrentId,
                    'subject'   => $subject,
                ]);
            }
        }

        $bytes = $this->mam->downloadTorrentFile((string) ($mamExtra['dlHash'] ?? ''));
        if ($bytes === null) {
            throw new \RuntimeException('MyAnonamouse did not serve the .torrent file — the session cookie may be expired or the release withdrawn.');
        }

        $url = (string) $candidate->downloadUrl;

        // Stamp the winning release. Clamp the torrent id to the source_id column
        // width (the full URL lives in download_url / candidate_links, both TEXT).
        $job->setSource('mam')
            ->setSourceId(mb_substr($candidate->sourceId, 0, 255))
            ->setProtocol(ReleaseCandidate::PROTOCOL_TORRENT)
            ->setFormat($candidate->format !== null ? mb_substr($candidate->format, 0, 16) : null)
            ->setSizeBytes($candidate->sizeBytes)
            ->setCandidateLinks([$url])
            ->setDownloadUrl($url);

        $hash = $client->addDownload($url, $subject, ['fileContents' => $bytes]);

        $job->setClientRef($hash)
            ->setStatus(DownloadJob::STATUS_DOWNLOADING)
            ->setProgress(0)
            ->setStatusMessage('Downloading via download client…');
        $job->getBookRequest()?->setDeliveryStatus(DownloadJob::STATUS_DOWNLOADING);

        $this->log->info(
            sprintf('Added to download client: %s (%d seeders)', $candidate->indexer ?? 'MyAnonamouse', $candidate->seeders ?? 0),
            $subject,
        );

        return true;
    }

    /**
     * Whether grabbing this candidate already costs the operator nothing —
     * sitewide freeleech, a personal freeleech, or (for VIP accounts, per the
     * cached account snapshot) a VIP freeleech. A wedge must never be spent on
     * these. Accepts the candidate or its extra['mam'] bag.
     *
     * @param ReleaseCandidate|array<string, mixed> $candidate
     */
    private function alreadyFreeForUser(ReleaseCandidate|array $candidate): bool
    {
        $mamExtra = $candidate instanceof ReleaseCandidate
            ? (is_array($candidate->extra['mam'] ?? null) ? $candidate->extra['mam'] : [])
            : $candidate;

        $isVip = (bool) ($this->settings->getMamAccountState()['isVip'] ?? false);

        return (bool) ($mamExtra['free'] ?? false)
            || (bool) ($mamExtra['personalFreeleech'] ?? false)
            || ($isVip && (bool) ($mamExtra['flVip'] ?? false));
    }

    /**
     * The ranking criteria for a MAM search: MAM's own seed floor, the shared
     * Prowlarr size cap and axis weights, and a format rank matching what the plan
     * is actually after (audio containers vs ebook formats).
     */
    private function policyFor(ReleaseSearchPlan $plan): TorrentMatchPolicy
    {
        $shared = $this->integrations->getProwlarrConfig()->matchPolicy();

        return new TorrentMatchPolicy(
            minSeeders: $this->settings->getMyAnonamouseConfig()->minSeeders,
            maxSizeBytes: $shared->maxSizeBytes,
            weights: $shared->weights,
            formatRank: $plan->contentType === ReleaseCandidate::CONTENT_AUDIOBOOK
                ? TorrentMatchPolicy::FORMAT_RANK
                : TorrentMatchPolicy::EBOOK_FORMAT_RANK,
        );
    }

    /**
     * Drop candidates that are on the book's release blocklist (a previous grab of
     * that torrent completed with junk content). MAM blocks are recorded off the
     * job stamp, so the set is keyed 'mam|<torrent id>' plus the download URL.
     * Logs when anything was skipped; when everything was, the caller returns
     * false and the pipeline falls through to the next source.
     *
     * @param list<ReleaseCandidate> $candidates
     * @return list<ReleaseCandidate>
     */
    private function withoutBlocked(array $candidates, ReleaseSearchPlan $plan, string $subject): array
    {
        $bookId = $plan->book->getId();
        $blocked = $bookId !== null ? $this->blockedReleases->blockedKeysForBook($bookId) : [];
        if ($blocked === []) {
            return $candidates;
        }

        $kept = [];
        foreach ($candidates as $c) {
            $key = 'mam|' . mb_substr($c->sourceId, 0, 255);
            if (isset($blocked[$key]) || ($c->downloadUrl !== null && $c->downloadUrl !== '' && isset($blocked[$c->downloadUrl]))) {
                continue;
            }
            $kept[] = $c;
        }

        $dropped = \count($candidates) - \count($kept);
        if ($dropped > 0) {
            $this->log->info(sprintf('MyAnonamouse search — skipped %d blocked release(s)', $dropped), $subject);
        }

        return $kept;
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
