<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\FulfillmentLog;
use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryException;
use App\Integration\Grimmory\GrimmoryNativeClient;
use App\Message\TriggerGrimmorySidecarImport;
use App\Repository\IntegrationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asks Grimmory's native API to import the sidecar metadata files SpineScout
 * wrote next to audiobooks. Failures are logged but never rethrown: retrying a
 * whole-library import brings no new information (no retry storms), and the
 * next completed download will trigger a fresh import anyway.
 */
#[AsMessageHandler]
final class TriggerGrimmorySidecarImportHandler
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly GrimmoryNativeClient $nativeClient,
        private readonly FulfillmentLog $fulfillmentLog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerGrimmorySidecarImport $message): void
    {
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$this->nativeClient->isConfigured($integration)) {
            $this->logger->debug('Grimmory sidecar import skipped: native API not configured.');

            return;
        }

        try {
            $result = $this->nativeClient->importAllSidecars($integration);
        } catch (GrimmoryException $e) {
            $this->logger->warning('Grimmory sidecar import failed', ['error' => $e->getMessage()]);
            $this->fulfillmentLog->error('Grimmory sidecar import failed: ' . $e->getMessage());

            return;
        }

        $this->fulfillmentLog->info(sprintf(
            'Triggered Grimmory sidecar import (%d metadata file(s) applied)',
            $result['imported'],
        ));
    }
}
