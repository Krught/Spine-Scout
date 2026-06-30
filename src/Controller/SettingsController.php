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
use App\Message\RefreshHardcoverTrending;
use App\Message\RefreshOpenLibraryTrending;
use App\Message\SyncGrimmoryLibrary;
use App\Mirror\MirrorList;
use App\Mirror\MirrorListNormalizer;
use App\Repository\BookRepository;
use App\Repository\BookSectionEntryRepository;
use App\Repository\IntegrationRepository;
use App\Download\Torrent\TorrentClientConfig;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Torrent\ProwlarrConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'settings_')]
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
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
    ): Response {
        $app = $repository->getOrCreate(Integration::KIND_APP);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_general', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_general');
            }

            $app->setOverwriteMetadataEnabled($request->request->getBoolean('overwrite_metadata'));
            $app->setAutoApproveRequestsEnabled($request->request->getBoolean('auto_approve_requests'));
            $app->setAuthType(Integration::AUTH_NONE);
            $app->setEnabled(true);
            $this->persistOrTouch($em, $app);
            $em->flush();

            $this->addFlash('success', 'General settings saved.');
            return $this->redirectToRoute('settings_general');
        }

        return $this->render('settings/general.html.twig', [
            'active_tab' => 'general',
            'overwrite_metadata' => $app->isOverwriteMetadataEnabled(),
            'auto_approve_requests' => $app->isAutoApproveRequestsEnabled(),
        ]);
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

            // Priority: keep only the fixed, known source ids, in posted order;
            // then backfill any missing so all sources persist.
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

            // Mirrors: one free-text blob per HTTP source (torrent has none).
            $blobs = $request->request->all('mirrors');
            $mirrors = [];
            foreach (DirectDownloadSource::mirrorIds() as $id) {
                $blob = is_string($blobs[$id] ?? null) ? $blobs[$id] : '';
                $mirrors[$id] = $normalizer->normalizeBlob($blob);
            }

            $config = DirectDownloadConfig::fromArray(
                [
                    'indexerPriority'     => $priority,
                    'mirrors'             => $mirrors,
                    'fastDownloadEnabled' => $request->request->getBoolean('fastDownloadEnabled'),
                    'outputDirectory'     => (string) $request->request->get('outputDirectory', ''),
                    'filenameTemplate'    => (string) $request->request->get('filenameTemplate', ''),
                    'bypassMode'          => (string) $request->request->get('bypassMode', DirectDownloadConfig::BYPASS_EXTERNAL),
                    'bypassFlaresolverrUrl' => (string) $request->request->get('bypassFlaresolverrUrl', ''),
                ],
                $normalizer,
            );
            $integrations->saveDirectDownloadConfig($config, $enabled, $em);
            $em->flush();

            $this->addFlash('success', 'Direct-download settings saved.');
            return $this->redirectToRoute('settings_direct_download');
        }

        $integration = $integrations->getOrCreate(Integration::KIND_DIRECT_DOWNLOAD);
        $config = $integrations->getDirectDownloadConfig();

        // Ordered view with all sources present: stored priority first, then any
        // not-yet-configured sources in default order. Sources absent from stored
        // config default to enabled so a fresh install just needs URLs pasted in.
        $enabledById = [];
        foreach ($config->indexerPriority as $row) {
            $enabledById[$row['id']] = $row['enabled'];
        }

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

        $priorityRows = [];
        $sourceLabels = [];
        $mirrorSections = [];
        foreach ($orderedIds as $id) {
            $source = DirectDownloadSource::from($id);
            $priorityRows[] = ['id' => $id, 'enabled' => $enabledById[$id] ?? true];
            $sourceLabels[$id] = $source->label();
            // Torrent has no operator-supplied mirror URLs (it uses the indexers +
            // download client from Settings → Torrents), so it gets a priority row but
            // no mirror textarea.
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
            'priority_rows'        => $priorityRows,
            'source_labels'        => $sourceLabels,
            'mirror_sections'      => $mirrorSections,
            'fast_download_enabled' => $config->fastDownloadEnabled,
            'output_directory'      => $config->outputDirectory,
            'filename_template'     => $config->filenameTemplate,
            'bypass_mode'           => $config->bypassMode,
            'bypass_flaresolverr_url' => $config->bypassFlaresolverrUrl,
        ]);
    }

    #[Route('/audiobooks', name: 'audiobooks')]
    public function audiobooks(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
    ): Response {
        $prowlarr = $integrations->getOrCreate(Integration::KIND_PROWLARR);
        $qbittorrent = $integrations->getOrCreate(Integration::KIND_QBITTORRENT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings_audiobooks', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('settings_audiobooks');
            }

            $req = $request->request;

            // -- Prowlarr: connection on the entity, tuning in the config blob.
            $prowlarr->setAuthType(Integration::AUTH_API_KEY);
            $prowlarr->setBaseUrl(self::blankToNull((string) $req->get('prowlarr_base_url', '')));
            $this->applyApiToken($prowlarr, (string) $req->get('prowlarr_api_key', ''));
            $prowlarrConfig = ProwlarrConfig::fromArray([
                'categories'   => self::parseIntCsv((string) $req->get('prowlarr_categories', '')),
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
            $qbittorrent->setAuthType(Integration::AUTH_BASIC);
            $qbittorrent->setBaseUrl(self::blankToNull((string) $req->get('qbittorrent_base_url', '')));
            $this->applyBasicCreds(
                $qbittorrent,
                (string) $req->get('qbittorrent_username', ''),
                (string) $req->get('qbittorrent_password', ''),
            );
            $clientConfig = TorrentClientConfig::fromArray([
                'category'             => (string) $req->get('qbittorrent_category', ''),
                'audioOutputDirectory' => (string) $req->get('audio_output_directory', ''),
                'useEbookLibraryDir'   => $req->getBoolean('use_ebook_library_dir'),
                'stagingSubdir'        => (string) $req->get('staging_subdir', ''),
                'filenameTemplate'     => (string) $req->get('torrent_filename_template', ''),
            ]);
            $qbittorrent->setEnabled(
                $req->getBoolean('qbittorrent_enabled')
                && $qbittorrent->getBaseUrl() !== null && $qbittorrent->getBaseUrl() !== '',
            );
            $qbittorrent->setOptions(['config' => $clientConfig->toArray()]);
            $this->persistOrTouch($em, $qbittorrent);

            $em->flush();
            $this->addFlash('success', 'Audiobook settings saved.');
            return $this->redirectToRoute('settings_audiobooks');
        }

        $prowlarrConfig = $integrations->getProwlarrConfig();
        $clientConfig = $integrations->getTorrentClientConfig();

        return $this->render('settings/audiobooks.html.twig', [
            'active_tab'      => 'audiobooks',
            'prowlarr'        => $prowlarr,
            'qbittorrent'     => $qbittorrent,
            'prowlarr_config' => $prowlarrConfig,
            'client_config'   => $clientConfig,
            'ebook_output_directory' => $integrations->getDirectDownloadConfig()->outputDirectory,
        ]);
    }

    #[Route('/audiobooks/test/prowlarr', name: 'audiobooks_test_prowlarr', methods: ['POST'])]
    public function testProwlarr(Request $request, \App\Integration\Prowlarr\ProwlarrClient $prowlarr): Response
    {
        if (!$this->isCsrfTokenValid('settings_audiobooks_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        [$ok, $message] = $prowlarr->testConnection();

        return $this->json(['ok' => $ok, 'message' => $message]);
    }

    #[Route('/audiobooks/test/qbittorrent', name: 'audiobooks_test_qbittorrent', methods: ['POST'])]
    public function testQbittorrent(Request $request, \App\Download\Client\QbittorrentDownloadClient $qbittorrent): Response
    {
        if (!$this->isCsrfTokenValid('settings_audiobooks_test', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        [$ok, $message] = $qbittorrent->testConnection();

        return $this->json(['ok' => $ok, 'message' => $message]);
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

    /** Apply an API-key token, keeping the stored one when the field is left blank. */
    private function applyApiToken(Integration $integration, string $token): void
    {
        $existing = $integration->getCredentials();
        $next = ['token' => $token !== '' ? $token : ($existing['token'] ?? null)];
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
