<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\TorrentClientSettings;
use App\Message\RewriteAllAudiobookSidecars;
use App\Message\RewriteAudiobookSidecar;
use App\Message\TriggerGrimmorySidecarImport;
use App\Repository\DownloadJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Fans the library-wide "rewrite all audiobook sidecars" action out into one
 * {@see RewriteAudiobookSidecar} per completed audiobook download, so each rewrite
 * (which fetches a cover over HTTP) is processed and retried independently rather
 * than blocking a single long-running handler. Skipped entirely when the operator
 * disabled Grimmory sidecars.
 */
#[AsMessageHandler]
final class RewriteAllAudiobookSidecarsHandler
{
    public function __construct(
        private readonly DownloadJobRepository $jobs,
        private readonly TorrentClientSettings $integrations,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RewriteAllAudiobookSidecars $message): void
    {
        if (!$this->integrations->getTorrentClientConfig()->writeGrimmorySidecars) {
            $this->logger->info('Sidecar rewrite-all skipped: Grimmory sidecars are disabled');

            return;
        }

        $ids = $this->jobs->completedAudiobookJobIds();
        foreach ($ids as $id) {
            $this->bus->dispatch(new RewriteAudiobookSidecar($id));
        }
        $this->logger->info('Queued audiobook sidecar rewrites', ['count' => \count($ids)]);

        // Ask Grimmory to import the rewritten sidecars — delayed 5 minutes so the
        // per-job rewrites above have landed on disk before the import-all runs.
        $this->bus->dispatch(new TriggerGrimmorySidecarImport(), [new DelayStamp(300_000)]);
    }
}
