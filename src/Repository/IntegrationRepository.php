<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Integration;
use App\Mirror\MirrorListNormalizer;
use App\Download\Client\TorrentClientSettings;
use App\Download\Torrent\TorrentClientConfig;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Torrent\ProwlarrConfig;
use App\Service\AppSettingsProvider;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 */
#[AsDoctrineListener(event: Events::onClear)]
final class IntegrationRepository extends ServiceEntityRepository implements SearchSettingsProvider, AppSettingsProvider, TorrentClientSettings
{
    /**
     * Per-request memo of the settings rows. Every settings accessor below funnels through
     * findByKind(), and a single request/message typically asks for the same handful of kinds
     * many times over; without this each call is its own SELECT.
     *
     * @var array<string, Integration|null>
     */
    private array $memo = [];

    public function __construct(
        ManagerRegistry $registry,
        private readonly MirrorListNormalizer $mirrorNormalizer,
    ) {
        parent::__construct($registry, Integration::class);
    }

    public function findByKind(string $kind): ?Integration
    {
        if (array_key_exists($kind, $this->memo)) {
            $cached = $this->memo[$kind];
            // Second line of defence behind onClear() below: if a memoized row is no longer in
            // the identity map (individual detach, a clear we somehow missed) the whole memo is
            // treated as belonging to a dead unit of work and dropped -- including memoized
            // nulls, which cannot be probed with contains() and would otherwise keep hiding a
            // row created since.
            if ($cached === null || $this->getEntityManager()->contains($cached)) {
                return $cached;
            }
            $this->memo = [];
        }

        return $this->memo[$kind] = $this->findOneBy(['kind' => $kind]);
    }

    /**
     * The repository is a long-lived service while the identity map is not: the messenger worker
     * stays up for many messages and Doctrine clears the EntityManager between them. Without this
     * the memo would keep serving settings captured when the worker booted, so an operator
     * toggling a setting in the UI would never reach the next handled message. Clearing the EM is
     * exactly the boundary at which the memo stops being "per request", so we drop it there.
     */
    public function onClear(): void
    {
        $this->memo = [];
    }

    public function getOrCreate(string $kind): Integration
    {
        return $this->findByKind($kind) ?? new Integration($kind);
    }

    /**
     * Drops the per-request memo. Called by every write path here so a freshly persisted or
     * mutated row is re-read instead of served from a stale (or unsaved-id) memo entry.
     */
    public function clearSettingsCache(): void
    {
        $this->memo = [];
    }

    public function qbittorrentIntegration(): ?Integration
    {
        return $this->findByKind(Integration::KIND_QBITTORRENT);
    }

    // -- app (General tab) ------------------------------------------------------

    public function isMetadataOverwriteEnabled(): bool
    {
        return $this->getOrCreate(Integration::KIND_APP)->isOverwriteMetadataEnabled();
    }

    public function isAutoApproveRequestsEnabled(): bool
    {
        return $this->getOrCreate(Integration::KIND_APP)->isAutoApproveRequestsEnabled();
    }

    /**
     * Operator toggle for the automatic search/fulfillment pipeline (KIND_APP row).
     * Defaults to true when the row/option is missing.
     */
    public function isAutomaticFulfillmentEnabled(): bool
    {
        return $this->getOrCreate(Integration::KIND_APP)->isAutomaticFulfillmentEnabled();
    }

    public function setAutomaticFulfillmentEnabled(bool $enabled): void
    {
        $em = $this->getEntityManager();
        $app = $this->getOrCreate(Integration::KIND_APP);
        $app->setAutomaticFulfillmentEnabled($enabled);
        if ($app->getId() === null) {
            $app->setAuthType(Integration::AUTH_NONE);
            $app->setEnabled(true);
            $em->persist($app);
        } else {
            $app->touch();
        }
        $em->flush();
        $this->clearSettingsCache();
    }

    // -- best_match -------------------------------------------------------------

    public function getBestMatchPolicy(): BestMatchPolicy
    {
        $row = $this->findByKind(Integration::KIND_BEST_MATCH);
        if ($row === null) {
            return BestMatchPolicy::default();
        }
        $raw = $row->getOptions()['policy'] ?? null;
        return BestMatchPolicy::fromArray(is_array($raw) ? $raw : null);
    }

    public function saveBestMatchPolicy(BestMatchPolicy $policy, EntityManagerInterface $em): Integration
    {
        $integration = $this->getOrCreate(Integration::KIND_BEST_MATCH);
        $integration->setAuthType(Integration::AUTH_NONE);
        $integration->setEnabled(true);
        $integration->setOptions(['policy' => $policy->toArray()]);
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        // The caller owns the flush; drop the memo now so nothing hands out the pre-write state
        // (or a memoized null shadowing the row we just persisted) later in this request.
        $this->clearSettingsCache();
        return $integration;
    }

    // -- direct_download --------------------------------------------------------

    public function getDirectDownloadConfig(): DirectDownloadConfig
    {
        $row = $this->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        if ($row === null) {
            return DirectDownloadConfig::default();
        }
        $raw = $row->getOptions()['config'] ?? null;
        return DirectDownloadConfig::fromArray(is_array($raw) ? $raw : null, $this->mirrorNormalizer);
    }

    public function saveDirectDownloadConfig(
        DirectDownloadConfig $config,
        bool $enabled,
        EntityManagerInterface $em,
    ): Integration {
        $integration = $this->getOrCreate(Integration::KIND_DIRECT_DOWNLOAD);
        $integration->setAuthType(Integration::AUTH_NONE);
        $integration->setEnabled($enabled);
        $integration->setOptions(['config' => $config->toArray()]);
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        // The caller owns the flush; drop the memo now so nothing hands out the pre-write state
        // (or a memoized null shadowing the row we just persisted) later in this request.
        $this->clearSettingsCache();
        return $integration;
    }

    // -- prowlarr (audiobook torrent search) ------------------------------------

    public function getProwlarrConfig(): ProwlarrConfig
    {
        $row = $this->findByKind(Integration::KIND_PROWLARR);
        if ($row === null) {
            return ProwlarrConfig::default();
        }
        $raw = $row->getOptions()['config'] ?? null;
        return ProwlarrConfig::fromArray(is_array($raw) ? $raw : null);
    }

    public function saveProwlarrConfig(
        ProwlarrConfig $config,
        bool $enabled,
        EntityManagerInterface $em,
    ): Integration {
        $integration = $this->getOrCreate(Integration::KIND_PROWLARR);
        $integration->setAuthType(Integration::AUTH_API_KEY);
        $integration->setEnabled($enabled);
        $integration->setOptions(['config' => $config->toArray()]);
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        // The caller owns the flush; drop the memo now so nothing hands out the pre-write state
        // (or a memoized null shadowing the row we just persisted) later in this request.
        $this->clearSettingsCache();
        return $integration;
    }

    // -- qbittorrent (audiobook torrent download client) ------------------------

    public function getTorrentClientConfig(): TorrentClientConfig
    {
        $row = $this->findByKind(Integration::KIND_QBITTORRENT);
        if ($row === null) {
            return TorrentClientConfig::default();
        }
        $raw = $row->getOptions()['config'] ?? null;
        return TorrentClientConfig::fromArray(is_array($raw) ? $raw : null);
    }

    public function saveTorrentClientConfig(
        TorrentClientConfig $config,
        bool $enabled,
        EntityManagerInterface $em,
    ): Integration {
        $integration = $this->getOrCreate(Integration::KIND_QBITTORRENT);
        $integration->setAuthType(Integration::AUTH_BASIC);
        $integration->setEnabled($enabled);
        $integration->setOptions(['config' => $config->toArray()]);
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        // The caller owns the flush; drop the memo now so nothing hands out the pre-write state
        // (or a memoized null shadowing the row we just persisted) later in this request.
        $this->clearSettingsCache();
        return $integration;
    }
}
