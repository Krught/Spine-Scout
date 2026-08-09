<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\Integration;
use App\Form\GrimmoryIntegrationType;
use App\Form\HardcoverIntegrationType;
use App\Form\OpenLibraryIntegrationType;
use App\Entity\BookSectionEntry;
use App\Message\PurgeStaleBooks;
use App\Message\ReconcileTorrents;
use App\Message\RefreshHardcoverTrending;
use App\Message\RefreshOpenLibraryTrending;
use App\Message\RewriteAllAudiobookSidecars;
use App\Message\SyncGrimmoryLibrary;
use App\Repository\DownloadJobRepository;
use App\Mirror\MirrorList;
use App\Mirror\MirrorListNormalizer;
use App\Repository\BookRepository;
use App\Repository\BookSectionEntryRepository;
use App\Repository\IntegrationRepository;
use App\Download\Torrent\TorrentClientConfig;
use App\Search\BestMatch\BestMatchPolicy;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Torrent\ProwlarrConfig;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'settings_')]
#[IsGranted('ROLE_MANAGE_SETTINGS')]
final class SettingsController extends AbstractController
{
    /** Hardcover lookups are paced at one per second; the rest wait for the scheduled sweep. */
    private const MAM_REFRESH_RESOLUTION_BUDGET = 25;

    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->redirectToRoute('settings_general');
    }

    #[Route('/general', name: 'general')]
    public function general(
        Request $request,
        IntegrationRepository $repository,
        EntityManagerInterface $em,
        MirrorListNormalizer $normalizer,
    ): Response {
        $app = $repository->getOrCreate(Integration::KIND_APP);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_general', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_general');
            }

            $app->setOverwriteMetadataEnabled($request->request->getBoolean('overwrite_metadata'));
            $app->setAutoApproveRequestsEnabled($request->request->getBoolean('auto_approve_requests'));
            $app->setAutomaticFulfillmentEnabled($request->request->getBoolean('automatic_fulfillment'));
            $app->setAuthType(Integration::AUTH_NONE);
            $app->setEnabled(true);
            $this->persistOrTouch($em, $app);

            // Source priority lives on this page but is stored with the rest of the
            // direct-download config. Merge-save: only the priority list is replaced —
            // mirrors/bypass (owned by Settings → Direct downloads), delivery/naming
            // (owned by Settings → Ebooks) and the row's master enabled flag all
            // survive untouched. An absent row behaves
            // as master-on (see getDirectDownloadConfig()), so when this save has to
            // create the row it writes enabled=true to keep the effective state.
            $ddRow = $repository->getOrCreate(Integration::KIND_DIRECT_DOWNLOAD);
            $masterEnabled = $ddRow->getId() === null || $ddRow->isEnabled();
            $config = DirectDownloadConfig::fromArray(
                array_replace(
                    $repository->getDirectDownloadConfig()->toArray(),
                    ['indexerPriority' => $this->parseIndexerPriority($request)],
                ),
                $normalizer,
            );
            $repository->saveDirectDownloadConfig($config, $masterEnabled, $em);

            $em->flush();

            $this->addFlash('success', 'General settings saved.');
            return $this->redirectToRoute('settings_general');
        }

        $config = $repository->getDirectDownloadConfig();
        $priorityRows = [];
        $sourceLabels = [];
        foreach ($this->orderedSourceIds($config) as $id) {
            $priorityRows[] = ['id' => $id, 'enabled' => $this->storedIndexerTick($config, $id)];
            $sourceLabels[$id] = DirectDownloadSource::from($id)->label();
        }

        return $this->render('settings/general.html.twig', [
            'active_tab' => 'general',
            'overwrite_metadata' => $app->isOverwriteMetadataEnabled(),
            'auto_approve_requests' => $app->isAutoApproveRequestsEnabled(),
            'automatic_fulfillment' => $app->isAutomaticFulfillmentEnabled(),
            'priority_rows' => $priorityRows,
            'source_labels' => $sourceLabels,
            'direct_downloads_enabled' => $config->directDownloadsEnabled,
            // While the master switch is off the four mirror sources render locked
            // (dimmed, tick disabled) but keep their stored tick; torrent stays live.
            'locked_ids' => $config->directDownloadsEnabled ? [] : DirectDownloadSource::mirrorIds(),
        ]);
    }

    /**
     * Wipe the cover image cache. Covers re-download on demand as pages render, and each
     * re-fetch is stamped with the book's current identity — so this recovers from wrong
     * covers left behind by a library reindex that the automatic staleness check can't see.
     */
    #[Route('/general/clear-cover-cache', name: 'general_clear_cover_cache', methods: ['POST'])]
    public function clearCoverCache(Request $request, CoverCache $covers): Response
    {
        if (!$this->isCsrfTokenValid('settings_clear_cover_cache', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_general');
        }

        $removed = $covers->clearAll();
        $this->addFlash('success', sprintf(
            'Cover cache cleared — %d cached image%s removed. Covers will re-download as pages are viewed.',
            $removed,
            $removed === 1 ? '' : 's',
        ));

        return $this->redirectToRoute('settings_general');
    }

    #[Route('/grimmory', name: 'grimmory')]
    public function grimmory(
        Request $request,
        IntegrationRepository $repository,
        BookRepository $bookRepository,
        EntityManagerInterface $em,
    ): Response {
        $integration = $repository->getOrCreate(Integration::KIND_GRIMMORY);

        $form = $this->createForm(GrimmoryIntegrationType::class, $integration, [
            'has_existing_credentials' => $integration->hasCredentials(),
            'discovered_libraries' => $integration->getDiscoveredLibraries(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Explicit in case an older row predates the AUTH_BASIC default.
            $integration->setAuthType(Integration::AUTH_BASIC);
            $this->applyCredentials($form, $integration);
            $integration->setEnabled(
                $integration->getBaseUrl() !== null
                && $integration->getBaseUrl() !== ''
                && $integration->hasCredentials(),
            );

            if ($integration->getId() === null) {
                $em->persist($integration);
            } else {
                $integration->touch();
            }
            $em->flush();

            $this->addFlash('success', 'Komga settings saved.');

            return $this->redirectToRoute('settings_grimmory');
        }

        $libraryCount = $integration->getId() !== null
            ? $bookRepository->countActiveBySource(Book::SOURCE_GRIMMORY)
            : 0;

        $recentBooks = $integration->getId() !== null
            ? $bookRepository->findBy(
                ['source' => Book::SOURCE_GRIMMORY, 'removedAt' => null],
                ['lastSeenAt' => 'DESC'],
                25,
            )
            : [];

        return $this->render('settings/grimmory.html.twig', [
            'active_tab' => 'grimmory',
            'form' => $form,
            'integration' => $integration,
            'library_count' => $libraryCount,
            'recent_books' => $recentBooks,
        ]);
    }

    #[Route('/grimmory/sync', name: 'grimmory_sync', methods: ['POST'])]
    public function grimmorySync(
        Request $request,
        IntegrationRepository $repository,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid('grimmory_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_grimmory');
        }

        $integration = $repository->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            $this->addFlash('error', 'Komga integration is not enabled.');
            return $this->redirectToRoute('settings_grimmory');
        }

        $bus->dispatch(new SyncGrimmoryLibrary(force: true));
        $this->addFlash('success', 'Sync queued. Refresh in a moment to see results.');

        return $this->redirectToRoute('settings_grimmory');
    }

    #[Route('/metadata', name: 'metadata')]
    public function metadata(
        Request $request,
        IntegrationRepository $repository,
        EntityManagerInterface $em,
        BookSectionEntryRepository $sectionEntries,
    ): Response {
        $hardcover  = $repository->getOrCreate(Integration::KIND_HARDCOVER);
        $openLibrary = $repository->getOrCreate(Integration::KIND_OPENLIBRARY);

        $this->seedDefaults($hardcover, $openLibrary);

        // Trending counts are now derived from the live link table rather than the JSONB blob.
        $hardcoverTrendingCount = $this->countSection($em, Book::SOURCE_HARDCOVER, BookSectionEntry::SECTION_TRENDING);
        $openLibraryTrendingCount = $this->countSection($em, Book::SOURCE_OPENLIBRARY, BookSectionEntry::SECTION_TRENDING);
        $purgeThresholdDays = $hardcover->getBookPurgeThresholdDays();

        if ($request->isMethod('POST') && $request->request->has('purge_threshold')) {
            if (!$this->isCsrfTokenValid('metadata_purge_threshold', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
            } else {
                $days = (int) $request->request->get('purge_threshold');
                $hardcover->setBookPurgeThresholdDays($days);
                $openLibrary->setBookPurgeThresholdDays($days);
                $em->flush();
                $this->addFlash('success', 'Purge threshold saved.');
                return $this->redirectToRoute('settings_metadata');
            }
        }

        $hardcoverForm = $this->createForm(HardcoverIntegrationType::class, $hardcover, [
            'existing_token' => (string) ($hardcover->getCredentials()['token'] ?? ''),
            'edition_preferences' => $hardcover->getHardcoverEditionPreferences(),
        ]);
        $openLibraryForm = $this->createForm(OpenLibraryIntegrationType::class, $openLibrary);

        $hardcoverForm->handleRequest($request);
        if ($hardcoverForm->isSubmitted() && $hardcoverForm->isValid()) {
            $hardcover->setAuthType(Integration::AUTH_API_KEY);
            $this->applyHardcoverToken($hardcoverForm, $hardcover);
            $this->applyHardcoverEditionPrefs($hardcoverForm, $hardcover);
            // Can't be enabled without a token; clamp here so the UI doesn't lie about state.
            if ($hardcover->isEnabled() && !$hardcover->hasCredentials()) {
                $hardcover->setEnabled(false);
                $this->addFlash('error', 'Hardcover needs an API token before it can be enabled.');
            }
            $this->persistOrTouch($em, $hardcover);
            $em->flush();
            $this->addFlash('success', 'Hardcover settings saved.');
            return $this->redirectToRoute('settings_metadata');
        }

        $openLibraryForm->handleRequest($request);
        if ($openLibraryForm->isSubmitted() && $openLibraryForm->isValid()) {
            $openLibrary->setAuthType(Integration::AUTH_NONE);
            $this->persistOrTouch($em, $openLibrary);
            $em->flush();
            $this->addFlash('success', 'Open Library settings saved.');
            return $this->redirectToRoute('settings_metadata');
        }

        return $this->render('settings/metadata.html.twig', [
            'active_tab' => 'metadata',
            'hardcover_form' => $hardcoverForm,
            'openlibrary_form' => $openLibraryForm,
            'hardcover' => $hardcover,
            'openlibrary' => $openLibrary,
            'hardcover_trending_count' => $hardcoverTrendingCount,
            'openlibrary_trending_count' => $openLibraryTrendingCount,
            'purge_threshold_days' => $purgeThresholdDays,
        ]);
    }

    private function countSection(EntityManagerInterface $em, string $source, string $section): int
    {
        $value = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM book_section_entries WHERE source = :s AND section = :sec',
            ['s' => $source, 'sec' => $section],
        );
        return is_numeric($value) ? (int) $value : 0;
    }

    #[Route('/metadata/hardcover/refresh', name: 'metadata_hardcover_refresh', methods: ['POST'])]
    public function refreshHardcover(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('metadata_hardcover_refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_metadata');
        }
        $bus->dispatch(new RefreshHardcoverTrending(force: true));
        $this->addFlash('success', 'Hardcover refresh queued.');
        return $this->redirectToRoute('settings_metadata');
    }

    #[Route('/metadata/purge', name: 'metadata_purge', methods: ['POST'])]
    public function purgeStaleBooks(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('metadata_purge', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_metadata');
        }
        $bus->dispatch(new PurgeStaleBooks(force: true));
        $this->addFlash('success', 'Stale-book purge queued.');
        return $this->redirectToRoute('settings_metadata');
    }

    #[Route('/metadata/openlibrary/refresh', name: 'metadata_openlibrary_refresh', methods: ['POST'])]
    public function refreshOpenLibrary(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('metadata_openlibrary_refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_metadata');
        }
        $bus->dispatch(new RefreshOpenLibraryTrending(force: true));
        $this->addFlash('success', 'Open Library refresh queued.');
        return $this->redirectToRoute('settings_metadata');
    }

    private function seedDefaults(Integration $hardcover, Integration $openLibrary): void
    {
        if ($hardcover->getId() === null) {
            $hardcover->setAuthType(Integration::AUTH_API_KEY);
            $hardcover->setSyncIntervalMinutes(60);
        }
        if ($openLibrary->getId() === null) {
            $openLibrary->setAuthType(Integration::AUTH_NONE);
            $openLibrary->setSyncIntervalMinutes(60);
        }
    }

    private function persistOrTouch(EntityManagerInterface $em, Integration $integration): void
    {
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
    }

    private function applyHardcoverToken($form, Integration $integration): void
    {
        $existing = $integration->getCredentials();
        $token = (string) $form->get('apiToken')->getData();
        $next = ['token' => $token !== '' ? $token : ($existing['token'] ?? null)];
        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Normalizes CSV inputs to match Hardcover's casing: languages/countries lowercased
     * for `code3`/`code2`, formats title-cased for `physical_format`.
     */
    private function applyHardcoverEditionPrefs($form, Integration $integration): void
    {
        $parseCsv = static function (mixed $raw, string $case): array {
            if (!is_string($raw)) {
                return [];
            }
            $parts = array_map('trim', explode(',', $raw));
            $parts = array_filter($parts, static fn ($v) => $v !== '');
            return array_values(array_map(static fn (string $v) => match ($case) {
                'lower' => strtolower($v),
                'upper' => strtoupper($v),
                'title' => ucwords(strtolower($v)),
                default => $v,
            }, $parts));
        };
        $integration->setHardcoverEditionPreferences([
            'languages' => $parseCsv($form->get('preferredLanguages')->getData(), 'lower'),
            'formats'   => $parseCsv($form->get('preferredFormats')->getData(),   'title'),
            'countries' => $parseCsv($form->get('preferredCountries')->getData(), 'upper'),
        ]);
    }

    private function applyCredentials($form, Integration $integration): void
    {
        $existing = $integration->getCredentials();
        $username = (string) $form->get('username')->getData();
        $password = (string) $form->get('password')->getData();

        $next = [
            'username' => $username !== '' ? $username : ($existing['username'] ?? null),
            'password' => $password !== '' ? $password : ($existing['password'] ?? null),
        ];

        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }

    #[Route('/best-match', name: 'best_match')]
    public function bestMatch(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_best_match', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_best_match');
            }

            $policy = BestMatchPolicy::fromArray($this->buildPolicyFromRequest($request));
            $integrations->saveBestMatchPolicy($policy, $em);
            $em->flush();

            $this->addFlash('success', 'Best-match policy saved.');
            return $this->redirectToRoute('settings_best_match');
        }

        $policy = $integrations->getBestMatchPolicy();
        return $this->render('settings/best_match.html.twig', [
            'active_tab'        => 'best_match',
            'policy'            => $policy,
            'format_suggestions' => ['epub', 'mobi', 'azw3', 'azw', 'pdf', 'cbz', 'cbr', 'fb2', 'djvu', 'txt'],
            'language_suggestions' => ['en', 'es', 'fr', 'de', 'it', 'pt', 'ru', 'ja', 'zh'],
            'tie_breakers' => BestMatchPolicy::TIE_BREAKERS,
        ]);
    }

    #[Route('/direct-download', name: 'direct_download')]
    public function directDownload(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
        MirrorListNormalizer $normalizer,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_direct_download', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_direct_download');
            }

            $enabled = $request->request->getBoolean('enabled');

            // Mirrors: one free-text blob per HTTP source (torrent has none).
            $blobs = $request->request->all('mirrors');
            $mirrors = [];
            foreach (DirectDownloadSource::mirrorIds() as $id) {
                $blob = is_string($blobs[$id] ?? null) ? $blobs[$id] : '';
                $mirrors[$id] = $normalizer->normalizeBlob($blob);
            }

            // Merge-save: this page owns only the channel keys of the shared
            // direct-download blob (mirrors, fast downloads, Cloudflare bypass).
            // The priority list (Settings → General) and the ebook delivery keys
            // outputDirectory/filenameTemplate (Settings → Ebooks) are no longer
            // form fields here and must survive this save untouched.
            $config = DirectDownloadConfig::fromArray(
                array_replace(
                    $integrations->getDirectDownloadConfig()->toArray(),
                    [
                        'mirrors'             => $mirrors,
                        'fastDownloadEnabled' => $request->request->getBoolean('fastDownloadEnabled'),
                        'bypassMode'          => (string) $request->request->get('bypassMode', DirectDownloadConfig::BYPASS_EXTERNAL),
                        'bypassFlaresolverrUrl' => (string) $request->request->get('bypassFlaresolverrUrl', ''),
                    ],
                ),
                $normalizer,
            );
            $integrations->saveDirectDownloadConfig($config, $enabled, $em);
            $em->flush();

            $this->addFlash('success', 'Direct-download settings saved.');
            return $this->redirectToRoute('settings_direct_download');
        }

        $integration = $integrations->getOrCreate(Integration::KIND_DIRECT_DOWNLOAD);
        $config = $integrations->getDirectDownloadConfig();

        // Mirror sections follow the operator's priority order (owned by Settings →
        // General). Torrent has no operator-supplied mirror URLs (it uses the
        // indexers + download client from Settings → Torrents), so it gets no
        // mirror textarea.
        $mirrorSections = [];
        foreach ($this->orderedSourceIds($config) as $id) {
            $source = DirectDownloadSource::from($id);
            if ($source->usesMirrors()) {
                $mirrorSections[] = [
                    'id'    => $id,
                    'label' => $source->label(),
                    'help'  => $source->help(),
                    'urls'  => $config->mirrorsFor($id)->toArray(),
                ];
            }
        }

        return $this->render('settings/direct_download.html.twig', [
            'active_tab'            => 'direct_download',
            'integration'          => $integration,
            'mirror_sections'      => $mirrorSections,
            'fast_download_enabled' => $config->fastDownloadEnabled,
            'bypass_mode'           => $config->bypassMode,
            'bypass_flaresolverr_url' => $config->bypassFlaresolverrUrl,
        ]);
    }

    #[Route('/ebooks', name: 'ebooks')]
    public function ebooks(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
        MirrorListNormalizer $normalizer,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_ebooks', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_ebooks');
            }

            // Ebook delivery is stored with the rest of the direct-download config.
            // Merge-save: only the delivery keys are replaced — mirrors, bypass,
            // fast downloads, the priority list and the row's master enabled flag
            // (owned by Settings → Direct downloads / General) survive untouched.
            // An absent row behaves as master-on (see getDirectDownloadConfig()),
            // so when this save has to create the row it writes enabled=true to
            // keep the effective state.
            $ddRow = $integrations->getOrCreate(Integration::KIND_DIRECT_DOWNLOAD);
            $masterEnabled = $ddRow->getId() === null || $ddRow->isEnabled();
            $config = DirectDownloadConfig::fromArray(
                array_replace(
                    $integrations->getDirectDownloadConfig()->toArray(),
                    [
                        'outputDirectory'  => (string) $request->request->get('outputDirectory', ''),
                        'filenameTemplate' => (string) $request->request->get('filenameTemplate', ''),
                    ],
                ),
                $normalizer,
            );
            $integrations->saveDirectDownloadConfig($config, $masterEnabled, $em);
            $em->flush();

            $this->addFlash('success', 'Ebook settings saved.');
            return $this->redirectToRoute('settings_ebooks');
        }

        $config = $integrations->getDirectDownloadConfig();

        return $this->render('settings/ebooks.html.twig', [
            'active_tab'        => 'ebooks',
            'output_directory'  => $config->outputDirectory,
            'filename_template' => $config->filenameTemplate,
        ]);
    }

    #[Route('/torrents', name: 'torrents')]
    public function torrents(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
    ): Response {
        $prowlarr = $integrations->getOrCreate(Integration::KIND_PROWLARR);
        $qbittorrent = $integrations->getOrCreate(Integration::KIND_QBITTORRENT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_torrents', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_torrents');
            }

            $req = $request->request;

            // -- Prowlarr: connection on the entity, tuning in the config blob.
            $prowlarr->setAuthType(Integration::AUTH_API_KEY);
            $prowlarr->setBaseUrl(self::blankToNull((string) $req->get('prowlarr_base_url', '')));
            $this->applyApiToken($prowlarr, (string) $req->get('prowlarr_api_key', ''));
            $prowlarrConfig = ProwlarrConfig::fromArray([
                'categories'     => self::parseIntCsv((string) $req->get('prowlarr_categories', '')),
                'bookCategories' => self::parseIntCsv((string) $req->get('prowlarr_book_categories', '')),
                'searchMethod' => (string) $req->get('prowlarr_search_method', ''),
                'minSeeders'   => $req->get('prowlarr_min_seeders', ''),
                'maxSizeBytes' => self::gbToBytes((string) $req->get('prowlarr_max_size_gb', '')),
                'weights'      => [
                    'match'   => $req->get('prowlarr_weight_match', ''),
                    'seeders' => $req->get('prowlarr_weight_seeders', ''),
                    'size'    => $req->get('prowlarr_weight_size', ''),
                    'format'  => $req->get('prowlarr_weight_format', ''),
                ],
            ]);
            $prowlarr->setEnabled(
                $req->getBoolean('prowlarr_enabled')
                && $prowlarr->getBaseUrl() !== null && $prowlarr->getBaseUrl() !== ''
                && $prowlarr->hasCredentials(),
            );
            $prowlarr->setOptions(['config' => $prowlarrConfig->toArray()]);
            $this->persistOrTouch($em, $prowlarr);

            // -- qBittorrent: connection on the entity, destination in the config blob.
            // Auth method: cookie login (username/password) or the native stateless
            // API key (qBittorrent ≥ 5.2.0). Unknown values fall back to basic.
            $qbAuthMethod = $req->get('qbittorrent_auth_method') === Integration::AUTH_API_KEY
                ? Integration::AUTH_API_KEY
                : Integration::AUTH_BASIC;
            $qbittorrent->setAuthType($qbAuthMethod);
            $qbittorrent->setBaseUrl(self::blankToNull((string) $req->get('qbittorrent_base_url', '')));
            if ($qbAuthMethod === Integration::AUTH_API_KEY) {
                $this->applyApiToken($qbittorrent, (string) $req->get('qbittorrent_api_key', ''), 'api_key');
            } else {
                $this->applyBasicCreds(
                    $qbittorrent,
                    (string) $req->get('qbittorrent_username', ''),
                    (string) $req->get('qbittorrent_password', ''),
                );
            }
            // Merge-save: this page owns only the torrent-stack keys of the shared
            // TorrentClientConfig blob. The audiobook delivery/metadata keys belong
            // to Settings → Audiobooks and must survive this save untouched.
            $clientConfig = TorrentClientConfig::fromArray(array_replace(
                $integrations->getTorrentClientConfig()->toArray(),
                [
                    'category'               => (string) $req->get('qbittorrent_category', ''),
                    'removeOnComplete'       => $req->getBoolean('remove_on_complete'),
                    'reconcileIntervalHours' => $req->get('reconcile_interval_hours', ''),
                    'deletePromptEnabled'    => $req->getBoolean('delete_prompt_enabled'),
                    'deleteDefaultAction'    => (string) $req->get('delete_default_action', ''),
                    'releasedTag'            => (string) $req->get('released_tag', ''),
                ],
            ));
            $qbittorrent->setEnabled(
                $req->getBoolean('qbittorrent_enabled')
                && $qbittorrent->getBaseUrl() !== null && $qbittorrent->getBaseUrl() !== '',
            );
            $qbOptions = $qbittorrent->getOptions();
            $qbOptions['config'] = $clientConfig->toArray();
            $qbittorrent->setOptions($qbOptions);
            $this->persistOrTouch($em, $qbittorrent);

            $em->flush();
            $this->addFlash('success', 'Torrent settings saved.');
            return $this->redirectToRoute('settings_torrents');
        }

        $prowlarrConfig = $integrations->getProwlarrConfig();
        $clientConfig = $integrations->getTorrentClientConfig();

        return $this->render('settings/torrents.html.twig', [
            'active_tab'      => 'torrents',
            'prowlarr'        => $prowlarr,
            'qbittorrent'     => $qbittorrent,
            'prowlarr_config' => $prowlarrConfig,
            'client_config'   => $clientConfig,
        ]);
    }

    #[Route('/audiobooks', name: 'audiobooks')]
    public function audiobooks(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_audiobooks', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_audiobooks');
            }

            $req = $request->request;

            // Merge-save: this page owns only the audiobook delivery/metadata keys of
            // the shared TorrentClientConfig blob. The torrent-stack keys (category,
            // seeding policy, reconcile cadence) belong to Settings → Torrents and must
            // survive this save untouched — as must the qbittorrent row's connection
            // fields and enabled flag, which this page no longer edits at all.
            $qbittorrent = $integrations->getOrCreate(Integration::KIND_QBITTORRENT);
            $overrides = [
                'useEbookLibraryDir'    => $req->getBoolean('use_ebook_library_dir'),
                'stagingSubdir'         => (string) $req->get('staging_subdir', ''),
                'filenameTemplate'      => (string) $req->get('torrent_filename_template', ''),
                'writeAudioTags'        => $req->getBoolean('write_audio_tags'),
                'writeGrimmorySidecars' => $req->getBoolean('write_grimmory_sidecars'),
            ];
            // The output-folder input is disabled (and therefore not submitted) while
            // "deliver into the ebook library" is ticked — keep the stored path in
            // that case so unticking later restores it instead of resetting to default.
            if ($req->has('audio_output_directory')) {
                $overrides['audioOutputDirectory'] = (string) $req->get('audio_output_directory');
            }
            $clientConfig = TorrentClientConfig::fromArray(array_replace(
                $integrations->getTorrentClientConfig()->toArray(),
                $overrides,
            ));
            $qbOptions = $qbittorrent->getOptions();
            $qbOptions['config'] = $clientConfig->toArray();
            $qbittorrent->setOptions($qbOptions);
            $this->persistOrTouch($em, $qbittorrent);

            $this->applyGrimmoryNativeOptions($req, $integrations, $em);

            $em->flush();
            $this->addFlash('success', 'Audiobook settings saved.');
            return $this->redirectToRoute('settings_audiobooks');
        }

        $grimmory = $integrations->findByKind(Integration::KIND_GRIMMORY);
        $native = $grimmory?->getOptions()['native'] ?? null;
        $native = is_array($native) ? $native : [];

        return $this->render('settings/audiobooks.html.twig', [
            'active_tab'    => 'audiobooks',
            'client_config' => $integrations->getTorrentClientConfig(),
            'ebook_output_directory' => $integrations->getDirectDownloadConfig()->outputDirectory,
            'grimmory_native' => [
                'username'      => (string) ($native['username'] ?? ''),
                'hasPassword'   => (string) ($native['password'] ?? '') !== '',
                'sidecarImport' => (bool) ($native['sidecarImport'] ?? false),
            ],
        ]);
    }

    /**
     * Persist the Grimmory native-API account (JWT; sidecar import) under the
     * GRIMMORY row's options['native'] blob, merging into the existing options
     * so other keys survive. The password is write-only in the UI: blank keeps
     * the stored one.
     */
    private function applyGrimmoryNativeOptions(InputBag $req, IntegrationRepository $integrations, EntityManagerInterface $em): void
    {
        $grimmory = $integrations->getOrCreate(Integration::KIND_GRIMMORY);
        $options = $grimmory->getOptions();
        $existing = is_array($options['native'] ?? null) ? $options['native'] : [];

        $password = (string) $req->get('native_password', '');

        $options['native'] = [
            'username'      => (string) $req->get('native_username', ''),
            'password'      => $password !== '' ? $password : (string) ($existing['password'] ?? ''),
            'sidecarImport' => $req->getBoolean('native_sidecar_import'),
        ];
        $grimmory->setOptions($options);
        $this->persistOrTouch($em, $grimmory);
    }

    #[Route('/audiobooks/test/grimmory-native', name: 'audiobooks_test_grimmory_native', methods: ['POST'])]
    public function testGrimmoryNative(
        Request $request,
        IntegrationRepository $integrations,
        \App\Integration\Grimmory\GrimmoryNativeClient $nativeClient,
    ): Response {
        if (!$this->isCsrfTokenValid('settings_audiobooks_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        $grimmory = $integrations->findByKind(Integration::KIND_GRIMMORY);
        if ($grimmory === null) {
            return $this->json(['ok' => false, 'message' => 'Grimmory is not configured yet — set it up on the Komga tab.']);
        }
        [$ok, $message] = $nativeClient->testConnection($grimmory);

        return $this->json(['ok' => $ok, 'message' => $message]);
    }

    #[Route('/audiobooks/rewrite-sidecars', name: 'audiobooks_rewrite_sidecars', methods: ['POST'])]
    public function audiobooksRewriteSidecars(Request $request, DownloadJobRepository $jobs, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('audiobooks_rewrite_sidecars', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings_audiobooks');
        }

        $count = \count($jobs->completedAudiobookJobIds());
        if ($count === 0) {
            $this->addFlash('error', 'No downloaded audiobooks found to rewrite.');

            return $this->redirectToRoute('settings_audiobooks');
        }

        $bus->dispatch(new RewriteAllAudiobookSidecars());
        $this->addFlash('success', sprintf('Queued metadata sidecar rewrite for %d audiobook(s). Refresh in a moment.', $count));

        return $this->redirectToRoute('settings_audiobooks');
    }

    #[Route('/torrents/reconcile', name: 'torrents_reconcile', methods: ['POST'])]
    public function reconcileTorrents(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('torrents_reconcile', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings_torrents');
        }

        $bus->dispatch(new ReconcileTorrents(force: true));
        $this->addFlash('success', 'Torrent reconcile queued. Refresh in a moment to see results.');

        return $this->redirectToRoute('settings_torrents');
    }

    #[Route('/torrents/test/prowlarr', name: 'torrents_test_prowlarr', methods: ['POST'])]
    public function testProwlarr(Request $request, \App\Integration\Prowlarr\ProwlarrClient $prowlarr): Response
    {
        if (!$this->isCsrfTokenValid('settings_torrents_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        [$ok, $message] = $prowlarr->testConnection();

        return $this->json(['ok' => $ok, 'message' => $message]);
    }

    #[Route('/torrents/test/qbittorrent', name: 'torrents_test_qbittorrent', methods: ['POST'])]
    public function testQbittorrent(Request $request, \App\Download\Client\QbittorrentDownloadClient $qbittorrent): Response
    {
        if (!$this->isCsrfTokenValid('settings_torrents_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        [$ok, $message] = $qbittorrent->testConnection();

        return $this->json(['ok' => $ok, 'message' => $message]);
    }

    #[Route('/myanonamouse', name: 'myanonamouse')]
    public function myanonamouse(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_myanonamouse', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_myanonamouse');
            }

            $req = $request->request;

            $baseUrl = rtrim(trim((string) $req->get('baseUrl', '')), '/');
            // A blank box means "leave it at the default"; anything else is clamped by the VO.
            $vipFetchLimit = trim((string) $req->get('vipFetchLimit', ''));
            $config = new MyAnonamouseConfig(
                $req->getBoolean('enabled'),
                $baseUrl !== '' ? $baseUrl : MyAnonamouseConfig::DEFAULT_BASE_URL,
                $req->getBoolean('showOnHomepage'),
                $req->getBoolean('showBrowseShelf'),
                $req->getBoolean('bookFormatEnabled'),
                $req->getBoolean('audiobookFormatEnabled'),
                max(0, (int) $req->get('minSeeders', 0)),
                $req->getBoolean('fetchVipFreeleech'),
                $vipFetchLimit === '' ? MyAnonamouseConfig::DEFAULT_VIP_FETCH_LIMIT : (int) $vipFetchLimit,
                $req->getBoolean('dynamicSeedboxUpdate'),
                self::blankToNull((string) $req->get('proxyUrl', '')),
            );

            $integration = $integrations->saveMyAnonamouseConfig($config, $config->enabled, $em);
            $this->applyApiToken($integration, trim((string) $req->get('mam_id', '')), 'mam_id');
            $integration->setSyncIntervalMinutes(self::hoursToMinutes((string) $req->get('refreshIntervalHours', ''), 360));
            $em->flush();

            $this->addFlash('success', 'MyAnonamouse settings saved.');
            return $this->redirectToRoute('settings_myanonamouse');
        }

        $integration = $integrations->getOrCreate(Integration::KIND_MYANONAMOUSE);
        $intervalMinutes = $integration->getId() === null || $integration->getSyncIntervalMinutes() < 60
            ? 360
            : $integration->getSyncIntervalMinutes();

        return $this->render('settings/myanonamouse.html.twig', [
            'active_tab'   => 'myanonamouse',
            'integration'  => $integration,
            'config'       => $integrations->getMyAnonamouseConfig(),
            'account'      => $integrations->getMamAccountState(),
            'refresh_interval_hours' => max(1, (int) round($intervalMinutes / 60)),
            'mam_default_base_url' => MyAnonamouseConfig::DEFAULT_BASE_URL,
            'mam_vip_fetch_limit_min' => MyAnonamouseConfig::MIN_VIP_FETCH_LIMIT,
            'mam_vip_fetch_limit_max' => MyAnonamouseConfig::MAX_VIP_FETCH_LIMIT,
            'mam_refresh_resolution_budget' => self::MAM_REFRESH_RESOLUTION_BUDGET,
        ]);
    }

    #[Route('/myanonamouse/test', name: 'myanonamouse_test', methods: ['POST'])]
    public function testMyAnonamouse(
        Request $request,
        \App\Integration\MyAnonamouse\MyAnonamouseClient $client,
    ): Response {
        if (!$this->isCsrfTokenValid('settings_myanonamouse_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        return $this->json($client->testConnection());
    }

    /** A sweep is paged fetches plus paced Hardcover lookups, so it outlives the default limit. */
    #[Route('/myanonamouse/refresh', name: 'myanonamouse_refresh', methods: ['POST'])]
    public function refreshMyAnonamouse(
        Request $request,
        \App\Integration\MyAnonamouse\MamFreeleechRefresher $refresher,
    ): Response {
        if (!$this->isCsrfTokenValid('settings_myanonamouse_refresh', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        set_time_limit(120);
        $summary = $refresher->refresh(force: true, maxResolutions: self::MAM_REFRESH_RESOLUTION_BUDGET);

        if ($summary['skipped']) {
            return $this->json([
                'ok'      => false,
                'message' => 'MyAnonamouse is disabled or has no session cookie saved — nothing was refreshed.',
                'summary' => $summary,
            ]);
        }

        $message = sprintf(
            'Fetched %d item(s) — %d new, %d resolved, %d unmatched, %d pending.',
            $summary['fetched'],
            $summary['new'],
            $summary['resolved'],
            $summary['unmatched'],
            $summary['pendingLeft'],
        );

        return $this->json([
            'ok'      => $summary['error'] === null,
            'message' => $summary['error'] === null ? $message : $message . ' ' . $summary['error'],
            'summary' => $summary,
        ]);
    }

    /** Whole hours from a form field to minutes; blank/invalid falls back to $defaultMinutes. */
    private static function hoursToMinutes(string $raw, int $defaultMinutes): int
    {
        $v = trim($raw);
        if ($v === '' || !is_numeric($v) || (float) $v <= 0) {
            return $defaultMinutes;
        }

        return max(1, (int) round((float) $v * 60));
    }

    private static function blankToNull(string $value): ?string
    {
        $v = trim($value);
        return $v === '' ? null : $v;
    }

    /** @return list<int> */
    private static function parseIntCsv(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }
        return array_values(array_unique($out));
    }

    /** Convert a (possibly fractional) gigabyte string to bytes, or null when blank/invalid. */
    private static function gbToBytes(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '' || !is_numeric($v) || (float) $v <= 0) {
            return null;
        }
        return (int) round((float) $v * 1024 * 1024 * 1024);
    }

    /** Apply an API-key token under $key, keeping the stored one when the field is left blank. */
    private function applyApiToken(Integration $integration, string $token, string $key = 'token'): void
    {
        $existing = $integration->getCredentials();
        $next = [$key => $token !== '' ? $token : ($existing[$key] ?? null)];
        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }

    /** Apply basic credentials, keeping stored values when a field is left blank. */
    private function applyBasicCreds(Integration $integration, string $username, string $password): void
    {
        $existing = $integration->getCredentials();
        $next = [
            'username' => $username !== '' ? $username : ($existing['username'] ?? null),
            'password' => $password !== '' ? $password : ($existing['password'] ?? null),
        ];
        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Parse the posted Source-priority list (the orderable_list hidden field):
     * keep only the fixed, known source ids, in posted order; then backfill any
     * missing so all sources persist.
     *
     * @return list<array{id: string, enabled: bool}>
     */
    private function parseIndexerPriority(Request $request): array
    {
        $priority = [];
        $seen = [];
        foreach ((array) $this->decodeJsonField($request, 'indexerPriority') as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (!is_string($id) || DirectDownloadSource::tryFromId($id) === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $priority[] = ['id' => $id, 'enabled' => (bool) ($row['enabled'] ?? true)];
        }
        foreach (DirectDownloadSource::ids() as $id) {
            if (!isset($seen[$id])) {
                $priority[] = ['id' => $id, 'enabled' => false];
            }
        }

        return $priority;
    }

    /**
     * Ordered view with all sources present: stored priority first, then any
     * not-yet-configured sources in default order.
     *
     * @return list<string>
     */
    private function orderedSourceIds(DirectDownloadConfig $config): array
    {
        $orderedIds = [];
        foreach ($config->indexerPriority as $row) {
            if (DirectDownloadSource::tryFromId($row['id']) !== null && !in_array($row['id'], $orderedIds, true)) {
                $orderedIds[] = $row['id'];
            }
        }
        foreach (DirectDownloadSource::ids() as $id) {
            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        return $orderedIds;
    }

    /**
     * The STORED per-source tick — deliberately not isIndexerEnabled(), which is
     * force-false for mirror sources while the master switch is off: the priority
     * list must render (and round-trip) the saved ticks so re-enabling restores
     * them. Sources absent from stored config default to enabled so a fresh
     * install just needs URLs pasted in.
     */
    private function storedIndexerTick(DirectDownloadConfig $config, string $id): bool
    {
        foreach ($config->indexerPriority as $row) {
            if ($row['id'] === $id) {
                return $row['enabled'];
            }
        }

        return true;
    }

    /**
     * Decode a hidden form field that the orderable_list Stimulus controller
     * serializes as JSON. Returns null on missing/invalid input so the caller
     * can default sensibly.
     */
    private function decodeJsonField(Request $request, string $key): mixed
    {
        $raw = $request->request->get($key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPolicyFromRequest(Request $request): array
    {
        $req = $request->request;
        return [
            'formatPriority'   => $this->decodeJsonField($request, 'formatPriority') ?? [],
            'tieBreakers'      => $this->decodeJsonField($request, 'tieBreakers') ?? [],
            'minSizeBytes'     => $req->get('minSizeBytes') === '' ? null : $req->get('minSizeBytes'),
            'maxSizeBytes'     => $req->get('maxSizeBytes') === '' ? null : $req->get('maxSizeBytes'),
            'minSeeders'       => $req->get('minSeeders') === '' ? null : $req->get('minSeeders'),
            'requireIsbnMatch' => $req->getBoolean('requireIsbnMatch'),
            'languagePriority' => $this->decodeJsonField($request, 'languagePriority') ?? [],
            'minMatchScore'    => $req->get('minMatchScore') === '' ? null : $req->get('minMatchScore'),
        ];
    }

}
