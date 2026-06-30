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
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 */
final class IntegrationRepository extends ServiceEntityRepository implements SearchSettingsProvider, AppSettingsProvider, TorrentClientSettings
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MirrorListNormalizer $mirrorNormalizer,
    ) {
        parent::__construct($registry, Integration::class);
    }

    public function findByKind(string $kind): ?Integration
    {
        return $this->findOneBy(['kind' => $kind]);
    }

    public function getOrCreate(string $kind): Integration
    {
        return $this->findByKind($kind) ?? new Integration($kind);
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
        return $integration;
    }
}
