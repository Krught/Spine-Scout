<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dev\DevTools;
use App\Download\Client\HttpDownloadClient;
use App\Download\Progress\CollectingDownloadProgressReporter;
use App\Repository\DownloadJobRepository;
use App\Repository\FulfillmentEventRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Developer-only tooling under Settings → Development. Every action is gated by
 * DevTools (non-prod + non-production-branch) and ROLE_ADMIN; in production the
 * nav item is hidden AND the routes 404.
 */
#[IsGranted('ROLE_ADMIN')]
final class DevController extends AbstractController
{
    public function __construct(
        private readonly DevTools $devTools,
        private readonly DirectDownloadProbe $probe,
        private readonly MatchScorer $scorer,
        private readonly IntegrationRepository $integrations,
    ) {
    }

    #[Route('/development', name: 'dev_index')]
    public function index(): Response
    {
        $this->assertAvailable();

        return $this->redirectToRoute('dev_direct_download');
    }

    #[Route('/development/direct-download', name: 'dev_direct_download')]
    public function directDownload(Request $request): Response
    {
        $this->assertAvailable();

        $isbn = trim((string) $request->query->get('isbn', ''));
        $author = trim((string) $request->query->get('author', ''));
        $title = trim((string) $request->query->get('title', ''));
        // Optional request-side metadata — only affects scoring (extra categories),
        // not the search query itself.
        $publisher = trim((string) $request->query->get('publisher', ''));
        $year = trim((string) $request->query->get('year', ''));
        $language = trim((string) $request->query->get('language', ''));
        $doFetch = $request->query->has('fetch');
        $detailHash = trim((string) $request->query->get('detail', ''));
        $detailMirror = trim((string) $request->query->get('mirror', ''));
        $detailSource = trim((string) $request->query->get('source', ''));
        $detailInfoUrl = trim((string) $request->query->get('info', ''));
        $hasInputs = $isbn !== '' || $author !== '' || $title !== '';
        $submitted = $request->query->has('submit') || $doFetch;

        // Ephemeral per-source enable/disable overrides: when the form is submitted
        // (carries `sources_submitted`), the checked `enabled[]` ids define which
        // sources run THIS request only. Never persisted. Absent on first load →
        // use the saved config as-is.
        $savedConfig = $this->probe->config();
        $togglesSubmitted = $request->query->has('sources_submitted');
        $enabledOverride = $togglesSubmitted ? array_values(array_filter(
            (array) $request->query->all('enabled'),
            static fn ($v): bool => is_string($v) && $v !== '',
        )) : null;
        $effectiveConfig = $this->probe->effectiveConfig($enabledOverride);

        $plan = $this->probe->buildPlan($isbn, $author, $title, $publisher, $year, $language);

        // Generate URLs only (no network) vs Generate & fetch (run each source).
        $searches = [];
        $runs = null;
        if ($submitted && $hasInputs) {
            if ($doFetch) {
                $runs = $this->probe->run($plan, $effectiveConfig);
            } else {
                $searches = $this->probe->plannedSearches($plan, $effectiveConfig);
            }
        }

        // Per-row inspection: resolve ONE record's detail (ISBNs + links) for its
        // owning source and score just that record. The row's facts ride along on
        // the link so we can score without re-running the search.
        $detail = null;
        if ($detailHash !== '' && $detailMirror !== '' && $detailSource !== '') {
            $candidate = new ReleaseCandidate(
                source:    $detailSource,
                sourceId:  $detailHash,
                title:     trim((string) $request->query->get('rtitle', '')),
                language:  $this->blankToNull($request->query->get('rlanguage')),
                format:    $this->blankToNull($request->query->get('rformat')),
                infoUrl:   $detailInfoUrl !== '' ? $detailInfoUrl : null,
                author:    $this->blankToNull($request->query->get('rauthor')),
                publisher: $this->blankToNull($request->query->get('rpublisher')),
                year:      $this->blankToNull($request->query->get('ryear')),
                extra:     ['mirror' => $detailMirror],
            );
            $resolved = $this->probe->resolveDetail($detailSource, $candidate, $effectiveConfig);
            $detail = ['hash' => $detailHash, 'mirror' => $detailMirror, 'source' => $detailSource] + $resolved;

            if ($hasInputs && $detail['error'] === null) {
                $detail['score']     = $this->scorer->score($candidate->withIsbns($detail['isbns']), $plan);
                $detail['threshold'] = $this->integrations->getBestMatchPolicy()->minMatchScore;
            }
        }

        return $this->render('dev/direct_download.html.twig', [
            'active_tab'  => 'direct_download',
            'branch'      => $this->devTools->currentBranch(),
            'environment' => $this->devTools->environment(),
            'inputs'      => [
                'isbn' => $isbn, 'author' => $author, 'title' => $title,
                'publisher' => $publisher, 'year' => $year, 'language' => $language,
            ],
            'source_rows' => $this->sourceRows($savedConfig, $effectiveConfig),
            'searches'    => $searches,
            'runs'        => $runs,
            'detail'      => $detail,
        ]);
    }

    /**
     * One row per known source for the toggle UI: its saved + effective (ephemeral)
     * enabled state and mirror summary, in saved priority order then any not yet
     * configured.
     *
     * @return list<array{id: string, label: string, saved_enabled: bool, enabled: bool, count: int, first: string|null}>
     */
    private function sourceRows(
        \App\Search\DirectDownload\DirectDownloadConfig $saved,
        \App\Search\DirectDownload\DirectDownloadConfig $effective,
    ): array {
        $orderedIds = [];
        foreach ($saved->indexerPriority as $row) {
            if (DirectDownloadSource::tryFromId($row['id']) !== null && !in_array($row['id'], $orderedIds, true)) {
                $orderedIds[] = $row['id'];
            }
        }
        foreach (DirectDownloadSource::ids() as $id) {
            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        $rows = [];
        foreach ($orderedIds as $id) {
            $mirrors = $saved->mirrorsFor($id)->toArray();
            $rows[] = [
                'id'            => $id,
                'label'         => DirectDownloadSource::tryFromId($id)?->label() ?? $id,
                'saved_enabled' => $saved->isIndexerEnabled($id),
                'enabled'       => $effective->isIndexerEnabled($id),
                'count'         => count($mirrors),
                'first'         => $mirrors[0] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Download-ability test for one record's links. Given the download links the
     * detail view surfaced, attempt to download EACH (reusing the real download
     * client's fetch path — bypass, interstitial hop, challenge rejection) to a
     * temp file, record its byte size, and delete it. Nothing is kept; this only
     * reports whether a mirror's links actually serve a file. JSON in, JSON out.
     */
    #[Route('/development/direct-download/test', name: 'dev_direct_download_test', methods: ['POST'])]
    public function directDownloadTest(Request $request, HttpDownloadClient $downloader, LoggerInterface $logger): Response
    {
        $this->assertAvailable();

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid request body.'], 400);
        }
        if (!$this->isCsrfTokenValid('dev_dd_download_test', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $links = array_values(array_filter(
            (array) ($payload['links'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        if ($links === []) {
            return $this->json(['error' => 'No download links to test.'], 400);
        }

        $results = [];
        foreach ($links as $link) {
            // Capture the real workflow's progress trail (handed to bypasser →
            // cleared challenge → found partner link → streamed file) so the UI
            // can show that each link ran the production path, not a naive GET.
            $progress = new CollectingDownloadProgressReporter();
            try {
                ['bytes' => $bytes, 'preview' => $preview] = $downloader->probeDownload($link, ['progress' => $progress]);
                $logger->info('Direct-download link test succeeded', ['url' => $link, 'bytes' => $bytes, 'preview' => $preview]);
                $results[] = ['url' => $link, 'ok' => true, 'bytes' => $bytes, 'preview' => $preview, 'error' => null, 'steps' => $progress->entries()];
            } catch (\Throwable $e) {
                $logger->warning('Direct-download link test failed', ['url' => $link, 'error' => $e->getMessage()]);
                $results[] = ['url' => $link, 'ok' => false, 'bytes' => null, 'preview' => null, 'error' => $e->getMessage(), 'steps' => $progress->entries()];
            }
        }

        return $this->json(['results' => $results]);
    }

    #[Route('/development/downloads', name: 'dev_downloads')]
    public function downloads(FulfillmentEventRepository $events, DownloadJobRepository $jobs): Response
    {
        $this->assertAvailable();

        return $this->render('dev/downloads.html.twig', [
            'active_tab'  => 'downloads',
            'branch'      => $this->devTools->currentBranch(),
            'environment' => $this->devTools->environment(),
            'events'      => $events->recent(200),
            'jobs'        => $jobs->recent(40),
        ]);
    }

    #[Route('/development/downloads/feed', name: 'dev_downloads_feed')]
    public function downloadsFeed(FulfillmentEventRepository $events, DownloadJobRepository $jobs): Response
    {
        $this->assertAvailable();

        return $this->render('dev/_downloads_feed.html.twig', [
            'events' => $events->recent(200),
            'jobs'   => $jobs->recent(40),
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->devTools->isAvailable()) {
            throw $this->createNotFoundException('Development tools are not available in this environment.');
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
