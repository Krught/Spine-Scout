<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dev\DevTools;
use App\Repository\DownloadJobRepository;
use App\Repository\FulfillmentEventRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Match\MatchScorer;
use App\Search\Source\DirectHttp\DirectHttpSource;
use App\Search\Source\ReleaseCandidate;
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
        private readonly DirectHttpSource $source,
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
        $hasInputs = $isbn !== '' || $author !== '' || $title !== '';
        $submitted = $request->query->has('submit') || $doFetch;

        $plan = $this->probe->buildPlan($isbn, $author, $title, $publisher, $year, $language);

        $result = null;
        if ($submitted && $hasInputs) {
            ['mirror' => $mirror, 'url' => $url] = $this->probe->searchUrl($plan);
            $result = [
                'mirror' => $mirror,
                'url'    => $url,
                'fetch'  => ($doFetch && $url !== null) ? $this->probe->fetch($url) : null,
            ];
        }

        // Per-row inspection: parse ONE record's detail page and score just that
        // record. A single outbound request, so it never hits the timeout the
        // all-at-once score did. The row's facts (title/author/format) ride along
        // on the link so we can score without re-running the search.
        $detail = null;
        if ($detailHash !== '' && $detailMirror !== '') {
            $detail = ['hash' => $detailHash, 'mirror' => $detailMirror]
                + $this->source->fetchRecordDetail($detailMirror, $detailHash);

            if ($hasInputs && $detail['error'] === null) {
                $candidate = new ReleaseCandidate(
                    source:    DirectHttpSource::NAME,
                    sourceId:  $detailHash,
                    title:     trim((string) $request->query->get('rtitle', '')),
                    language:  $this->blankToNull($request->query->get('rlanguage')),
                    format:    $this->blankToNull($request->query->get('rformat')),
                    author:    $this->blankToNull($request->query->get('rauthor')),
                    isbns:     $detail['isbns'],
                    publisher: $this->blankToNull($request->query->get('rpublisher')),
                    year:      $this->blankToNull($request->query->get('ryear')),
                );
                $detail['score']     = $this->scorer->score($candidate, $plan);
                $detail['threshold'] = $this->integrations->getBestMatchPolicy()->minMatchScore;
            }
        }

        $config = $this->probe->config();
        $sourceRows = [];
        foreach ($config->indexerPriority as $row) {
            $src = DirectDownloadSource::tryFromId($row['id']);
            $mirrors = $config->mirrorsFor($row['id'])->toArray();
            $sourceRows[] = [
                'label'        => $src?->label() ?? $row['id'],
                'enabled'      => $row['enabled'],
                'count'        => count($mirrors),
                'first'        => $mirrors[0] ?? null,
                'searchSource' => $src?->isSearchSource() ?? false,
            ];
        }

        return $this->render('dev/direct_download.html.twig', [
            'active_tab'  => 'direct_download',
            'branch'      => $this->devTools->currentBranch(),
            'environment' => $this->devTools->environment(),
            'inputs'      => [
                'isbn' => $isbn, 'author' => $author, 'title' => $title,
                'publisher' => $publisher, 'year' => $year, 'language' => $language,
            ],
            'source_rows' => $sourceRows,
            'result'      => $result,
            'detail'      => $detail,
        ]);
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
