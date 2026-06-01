<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Integration;
use App\Mirror\MirrorListNormalizer;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Service\AppSettingsProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 */
final class IntegrationRepository extends ServiceEntityRepository implements SearchSettingsProvider, AppSettingsProvider
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

    // -- app (General tab) ------------------------------------------------------

    public function isMetadataOverwriteEnabled(): bool
    {
        return $this->getOrCreate(Integration::KIND_APP)->isOverwriteMetadataEnabled();
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
}
