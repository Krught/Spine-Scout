<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Integration;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;
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
final class IntegrationRepository extends ServiceEntityRepository implements SearchSettingsProvider, AppSettingsProvider, TorrentClientSettings, MyAnonamouseSettingsProvider
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
        // The row's enabled column rides along as the master switch for the mirror
        // sources; it is not part of the options blob (a missing row means "enabled",
        // matching DirectDownloadConfig::default()).
        return DirectDownloadConfig::fromArray(is_array($raw) ? $raw : null, $this->mirrorNormalizer, $row->isEnabled());
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

    // -- myanonamouse (freeleech catalog) ---------------------------------------

    public function getMyAnonamouseConfig(): MyAnonamouseConfig
    {
        $row = $this->findByKind(Integration::KIND_MYANONAMOUSE);
        if ($row === null) {
            return MyAnonamouseConfig::default();
        }
        $raw = $row->getOptions()['config'] ?? null;
        // The row's enabled column rides along as the master switch, and its baseUrl
        // column is authoritative over the copy in the blob (blank falls back to the
        // MAM origin) — neither is edited through the value object alone.
        $config = MyAnonamouseConfig::fromArray(is_array($raw) ? $raw : null, $row->isEnabled());
        $baseUrl = rtrim(trim((string) $row->getBaseUrl()), '/');
        if ($baseUrl === '' || $baseUrl === $config->baseUrl) {
            return $config;
        }

        return new MyAnonamouseConfig(
            $config->enabled,
            $baseUrl,
            $config->showOnHomepage,
            $config->showBrowseShelf,
            $config->bookFormatEnabled,
            $config->audiobookFormatEnabled,
            $config->minSeeders,
            $config->fetchVipFreeleech,
            $config->vipFetchLimit,
            $config->dynamicSeedboxUpdate,
            $config->proxyUrl,
        );
    }

    public function saveMyAnonamouseConfig(
        MyAnonamouseConfig $config,
        bool $enabled,
        EntityManagerInterface $em,
    ): Integration {
        $integration = $this->getOrCreate(Integration::KIND_MYANONAMOUSE);
        $integration->setAuthType(Integration::AUTH_API_KEY);
        $integration->setEnabled($enabled);
        $integration->setBaseUrl($config->baseUrl);
        // Merge rather than replace: the account snapshot (options['account']) is written
        // by the refresh handler and must survive an operator saving the settings tab.
        $options = $integration->getOptions();
        $options['config'] = $config->toArray();
        $integration->setOptions($options);
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

    public function getMamSessionCookie(): ?string
    {
        $row = $this->findByKind(Integration::KIND_MYANONAMOUSE);
        if ($row === null) {
            return null;
        }
        $value = trim((string) ($row->getCredentials()['mam_id'] ?? ''));

        return $value !== '' ? $value : null;
    }

    public function persistRotatedMamSessionCookie(string $newValue): void
    {
        $value = trim($newValue);
        if ($value === '') {
            return;
        }
        $em = $this->getEntityManager();
        $integration = $this->getOrCreate(Integration::KIND_MYANONAMOUSE);
        $credentials = $integration->getCredentials();
        $credentials['mam_id'] = $value;
        $integration->setCredentials($credentials);
        if ($integration->getId() === null) {
            $integration->setAuthType(Integration::AUTH_API_KEY);
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        $em->flush();
        $this->clearSettingsCache();
    }

    /** @return array<string, mixed> */
    public function getMamAccountState(): array
    {
        $row = $this->findByKind(Integration::KIND_MYANONAMOUSE);
        if ($row === null) {
            return [];
        }
        $state = $row->getOptions()['account'] ?? null;

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    public function saveMamAccountState(array $state): void
    {
        $em = $this->getEntityManager();
        $integration = $this->getOrCreate(Integration::KIND_MYANONAMOUSE);
        $options = $integration->getOptions();
        $options['account'] = $state;
        $integration->setOptions($options);
        if ($integration->getId() === null) {
            $integration->setAuthType(Integration::AUTH_API_KEY);
            $em->persist($integration);
        } else {
            $integration->touch();
        }
        $em->flush();
        $this->clearSettingsCache();
    }
}
