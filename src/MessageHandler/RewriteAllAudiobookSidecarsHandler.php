<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RewriteAllAudiobookSidecars;
use App\Message\RewriteAudiobookSidecar;
use App\Repository\DownloadJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans the library-wide "rewrite all audiobook sidecars" action out into one
 * {@see RewriteAudiobookSidecar} per completed audiobook download, so each rewrite
 * (which fetches a cover over HTTP) is processed and retried independently rather
 * than blocking a single long-running handler.
 */
#[AsMessageHandler]
final class RewriteAllAudiobookSidecarsHandler
{
    public function __construct(
        private readonly DownloadJobRepository $jobs,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RewriteAllAudiobookSidecars $message): void
    {
        $ids = $this->jobs->completedAudiobookJobIds();
        foreach ($ids as $id) {
            $this->bus->dispatch(new RewriteAudiobookSidecar($id));
        }
        $this->logger->info('Queued audiobook sidecar rewrites', ['count' => \count($ids)]);
    }
}
