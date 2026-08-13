<?php

declare(strict_types=1);

namespace App\Controller;

use App\Download\Client\DownloadClientInterface;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\Mam\MamFulfillment;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Progress\CollectingDownloadProgressReporter;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Entity\User;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;
use App\Integration\Prowlarr\ProwlarrClient;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\DirectDownload\ScoredCandidate;
use App\Search\Mam\MamCandidateMapper;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use App\Search\Torrent\ScoredRelease;
use App\Search\Torrent\TorrentMatchPolicy;
use App\Search\Torrent\TorrentMatchScorer;
use App\Service\BookMetadataService;
use App\Support\AudioFormat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User-facing "Interactive Search" — the manual counterpart to the automatic
 * fulfillment cascade. From a book modal the user picks a source, picks one of
 * its mirrors, edits the title/author/ISBN the query runs on, sees every result
 * with a relevance match %, then hand-picks one and downloads exactly that file
 * into the library.
 *
 * Reuses the same machinery as the developer probe (DirectDownloadProbe,
 * ReleaseSourceScorer) for search and scoring, and the same primitives as
 * ProcessDownloadJobHandler (download client → metadata injection → filename
 * template → FileMover) for the real download — here driven by a single
 * user-chosen candidate instead of the full cascade.
 *
 * Gated on the per-user ROLE_INTERACTIVE_SEARCH permission (admins inherit it via
 * role_hierarchy) — a logged-in user without it gets 403 from every route here, and
 * the UI that drives them is not rendered at all.
 */
#[IsGranted('ROLE_INTERACTIVE_SEARCH')]
final class InteractiveSearchController extends AbstractController
{
    /** Server-side cap on returned rows — scoring fetches a detail page per row. */
    private const MAX_RESULTS = 25;

    /** Cap on the category labels echoed per torrent row — the panel shows chips. */
    private const MAX_CATEGORIES = 4;

    private const CSRF_ID = 'interactive_search';

    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly DirectDownloadProbe $probe,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly FileMover $mover,
        private readonly FilenameTemplate $filenames,
        private readonly EbookMetadataInjector $metadataInjector,
        private readonly BookMetadataService $metadata,
        private readonly BookRepository $books,
        private readonly BookRequestRepository $requests,
        private readonly DownloadJobRepository $jobs,
        private readonly IntegrationRepository $integrations,
        private readonly ProwlarrClient $prowlarr,
        private readonly TorrentMatchScorer $scorer,
        private readonly TorrentFulfillmentInterface $torrents,
        private readonly MamFulfillment $mamFulfillment,
        private readonly MyAnonamouseClient $mam,
        private readonly MyAnonamouseSettingsProvider $mamSettings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The operator-enabled sources with their configured mirror URLs, so the panel
     * can render the source buttons and the mirror toggle. URLs are never shipped
     * in code — they come entirely from the saved config.
     */
    #[Route('/interactive-search/sources', name: 'interactive_search_sources', methods: ['POST'])]
    public function sources(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }

        $config = $this->probe->config();

        // Walk the operator's priority list in order, so the panel's first entry —
        // the one it preselects — is their highest-priority source. A source the
        // operator switched off is omitted entirely: the same
        // DirectDownloadConfig::isIndexerEnabled() predicate the automatic cascade
        // gates on (ProcessDownloadJobHandler, DirectDownloadEvaluator, the source
        // adapters), so the panel offers exactly what the pipeline would try.
        // Sources absent from the saved priority list are off by that predicate and
        // therefore also absent here. Torrent rides along with the mirror sources:
        // it has no mirror URLs, but is a pickable source backed by the indexer stack.
        $sources = [];
        $seen = [];
        foreach ($config->indexerPriority as $row) {
            $id = $row['id'] ?? null;
            $source = is_string($id) ? DirectDownloadSource::tryFromId($id) : null;
            if ($source === null || isset($seen[$source->value]) || !$config->isIndexerEnabled($source->value)) {
                continue;
            }
            $seen[$source->value] = true;

            // `enabled` is "usable right now", not "switched on": a source the
            // operator enabled but never finished configuring (no mirrors / no
            // torrent stack) still shows, greyed out, so they can see why.
            $isTorrent = $source === DirectDownloadSource::Torrent;
            $isMam = $source === DirectDownloadSource::Mam;
            $mirrors = ($isTorrent || $isMam) ? [] : $config->mirrorsFor($source->value)->toArray();
            $entry = [
                'id'      => $source->value,
                'label'   => $source->label(),
                'enabled' => match (true) {
                    $isTorrent => $this->torrents->isAvailable(),
                    $isMam     => $this->mamFulfillment->isAvailable(),
                    default    => $mirrors !== [],
                },
                'mirrors' => $mirrors,
            ];
            if ($isTorrent || $isMam) {
                // The operator's saved default; the panel's method toggle starts
                // here. MAM deliberately shares the Prowlarr default: the three
                // method ids are identical and it is the only persisted
                // search-method preference.
                $entry['searchMethod'] = $this->integrations->getProwlarrConfig()->searchMethod;
            }
            if ($isMam) {
                // Everything the panel's wedge toggle needs to pick its default
                // and explain a forced state, without another round-trip.
                $mamConfig = $this->mamSettings->getMyAnonamouseConfig();
                $entry['wedge'] = [
                    'userIsVip' => (bool) ($this->mamSettings->getMamAccountState()['isVip'] ?? false),
                    'alwaysUse' => $mamConfig->alwaysUseWedge,
                    'autoMinGb' => $mamConfig->autoWedgeMinGb,
                ];
            }
            $sources[] = $entry;
        }

        return $this->json(['sources' => $sources]);
    }

    /**
     * Run ONE source against ONE mirror with the user-edited title/author/ISBN,
     * scored. Returns every result with its match % and the concrete download
     * links the Manual Download step will use.
     */
    #[Route('/interactive-search/run', name: 'interactive_search_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);

        $sourceId = trim((string) ($payload['source'] ?? ''));
        if (DirectDownloadSource::tryFromId($sourceId) === null) {
            return $this->json(['error' => 'Unknown source.'], 400);
        }
        if ($sourceId === DirectDownloadSource::Torrent->value) {
            return $this->runTorrent($payload);
        }
        if ($sourceId === DirectDownloadSource::Mam->value) {
            return $this->runMam($payload);
        }
        $config = $this->probe->config();

        $mirror = trim((string) ($payload['mirror'] ?? ''));
        if ($mirror === '') {
            $mirror = $config->mirrorsFor($sourceId)->toArray()[0] ?? '';
        }
        if ($mirror === '') {
            return $this->json(['error' => 'No mirror configured for this source.'], 400);
        }

        $plan = $this->probe->buildPlan(
            trim((string) ($payload['isbn'] ?? '')),
            trim((string) ($payload['author'] ?? '')),
            trim((string) ($payload['title'] ?? '')),
            trim((string) ($payload['publisher'] ?? '')),
            trim((string) ($payload['year'] ?? '')),
            trim((string) ($payload['language'] ?? '')),
        );
        if (self::resolveAudiobook($payload, fn (): ?Book => $this->resolveBook($payload))) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        $scored = $this->probe->searchScoredVia($sourceId, $mirror, $plan, $config);
        $rows = array_map(
            static fn (ScoredCandidate $sc): array => self::row($sc),
            array_slice($scored, 0, self::MAX_RESULTS),
        );

        return $this->json([
            'source'    => $sourceId,
            'mirror'    => $mirror,
            'searchUrl' => $this->probe->searchUrlVia($sourceId, $mirror, $plan),
            'threshold' => $this->probe->matchThreshold(),
            'truncated' => \count($scored) > self::MAX_RESULTS,
            'acceptedFormats' => $this->acceptedFormats(),
            'results'   => $rows,
        ]);
    }

    /**
     * The torrent half of run(): search the configured indexers for the (possibly
     * user-edited) metadata and rank the releases with the same weighted policy the
     * automatic audiobook pipeline uses. No mirror is involved — the indexer manager
     * is the single search surface — so `mirror`/`searchUrl` come back null.
     *
     * @param array<string, mixed> $payload
     */
    private function runTorrent(array $payload): JsonResponse
    {
        if (!$this->torrents->isAvailable()) {
            return $this->json(['error' => 'Torrent search is not configured.'], 409);
        }

        // The query is always what the user typed into the panel's title/author/
        // ISBN fields — never the stored book metadata, so their edits actually
        // drive the search. The audiobook toggle only stamps the plan's content
        // type (indexer categories key off it), it never swaps the query.
        $book = $this->resolveBook($payload);
        $audiobook = self::resolveAudiobook($payload, static fn (): ?Book => $book);
        $plan = $this->probe->buildPlan(
            trim((string) ($payload['isbn'] ?? '')),
            trim((string) ($payload['author'] ?? '')),
            trim((string) ($payload['title'] ?? '')),
            trim((string) ($payload['publisher'] ?? '')),
            trim((string) ($payload['year'] ?? '')),
            trim((string) ($payload['language'] ?? '')),
        );
        if ($audiobook && $plan->contentType !== ReleaseCandidate::CONTENT_AUDIOBOOK) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        // Per-search override of the operator's default search method (the panel's
        // Categories / Raw / Filtered toggle); anything unrecognized falls back to
        // the saved default inside search().
        $searchMethod = $this->blankToNull($payload['searchMethod'] ?? null);

        $scored = $this->scorer->scored(
            $this->prowlarr->search($plan, is_string($searchMethod) ? $searchMethod : null),
            $plan,
            $this->integrations->getProwlarrConfig()->matchPolicy(),
        );

        $threshold = $this->probe->matchThreshold();
        $rows = array_map(
            static fn (ScoredRelease $sr): array => self::torrentRow($sr, $threshold, $plan->contentType),
            array_slice($scored, 0, self::MAX_RESULTS),
        );

        return $this->json([
            'source'    => DirectDownloadSource::Torrent->value,
            'mirror'    => null,
            'searchUrl' => null,
            'threshold' => $threshold,
            'truncated' => \count($scored) > self::MAX_RESULTS,
            'acceptedFormats' => $this->acceptedFormats(),
            'results'   => $rows,
        ]);
    }

    /**
     * The MAM half of run(), mirroring runTorrent(): search MyAnonamouse directly
     * (no Prowlarr, no mirror) with the user-edited metadata and rank with the same
     * weighted policy the MAM auto pipeline uses. Rows reuse the torrent row shape
     * (the panel's torrent table renders them unchanged) plus a `mam` block with
     * the freeleech facts the wedge toggle needs.
     *
     * @param array<string, mixed> $payload
     */
    private function runMam(array $payload): JsonResponse
    {
        if (!$this->mamFulfillment->isAvailable()) {
            return $this->json(['error' => 'MyAnonamouse search is not configured.'], 409);
        }

        // Same contract as runTorrent(): the query is what the user typed, the
        // audiobook toggle only stamps the plan's content type (MAM's main
        // category keys off it), it never swaps the query.
        $book = $this->resolveBook($payload);
        $audiobook = self::resolveAudiobook($payload, static fn (): ?Book => $book);
        $plan = $this->probe->buildPlan(
            trim((string) ($payload['isbn'] ?? '')),
            trim((string) ($payload['author'] ?? '')),
            trim((string) ($payload['title'] ?? '')),
            trim((string) ($payload['publisher'] ?? '')),
            trim((string) ($payload['year'] ?? '')),
            trim((string) ($payload['language'] ?? '')),
        );
        if ($audiobook && $plan->contentType !== ReleaseCandidate::CONTENT_AUDIOBOOK) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        // The panel's Categories / Raw / Filtered toggle — the same three ids the
        // torrent method toggle uses. Anything unrecognized falls back to the
        // categories default rather than failing the search.
        $method = $payload['searchMethod'] ?? null;
        if (!is_string($method) || !in_array($method, ProwlarrConfig::METHODS, true)) {
            $method = ProwlarrConfig::METHOD_CATEGORIES;
        }

        $mamConfig = $this->mamSettings->getMyAnonamouseConfig();
        $scored = $this->scorer->scored(
            MamCandidateMapper::mapAll(
                $this->mam->searchReleases($plan, $method, self::MAX_RESULTS),
                $mamConfig->baseUrl,
            ),
            $plan,
            $this->mamMatchPolicy($plan),
        );

        $threshold = $this->probe->matchThreshold();
        $userIsVip = (bool) ($this->mamSettings->getMamAccountState()['isVip'] ?? false);
        $rows = array_map(
            static fn (ScoredRelease $sr): array => self::mamRow($sr, $threshold, $plan->contentType, $userIsVip, $mamConfig),
            array_slice($scored, 0, self::MAX_RESULTS),
        );

        return $this->json([
            'source'    => DirectDownloadSource::Mam->value,
            'mirror'    => null,
            'searchUrl' => self::mamSearchUrl($plan, $mamConfig->baseUrl),
            'threshold' => $threshold,
            'truncated' => \count($scored) > self::MAX_RESULTS,
            'acceptedFormats' => $this->acceptedFormats(),
            'results'   => $rows,
        ]);
    }

    /**
     * One ranked MAM release: the torrent row shape (the indexer column reads
     * 'MyAnonamouse') plus a `mam` block. `alreadyFree` applies Prowlarr's
     * "already free for this user" rule (MamRelease::isFreeForUser — sitewide
     * freeleech, personal freeleech, or VIP freeleech for a VIP account), and
     * `wedgeDefault` is the same MyAnonamouseConfig::wedgeDecision() the auto
     * pipeline uses — the panel's wedge checkbox starts there.
     *
     * @return array<string, mixed>
     */
    private static function mamRow(ScoredRelease $sr, int $threshold, string $planContentType, bool $userIsVip, MyAnonamouseConfig $config): array
    {
        $row = self::torrentRow($sr, $threshold, $planContentType);

        $mam = is_array($sr->candidate->extra['mam'] ?? null) ? $sr->candidate->extra['mam'] : [];
        $free = (bool) ($mam['free'] ?? false);
        $flVip = (bool) ($mam['flVip'] ?? false);
        $personal = (bool) ($mam['personalFreeleech'] ?? false);
        $alreadyFree = $free || $personal || ($userIsVip && $flVip);

        $row['mam'] = [
            'torrentId'         => is_numeric($mam['torrentId'] ?? null) ? (int) $mam['torrentId'] : 0,
            'dlHash'            => (string) ($mam['dlHash'] ?? ''),
            'free'              => $free,
            'flVip'             => $flVip,
            'personalFreeleech' => $personal,
            'alreadyFree'       => $alreadyFree,
            'wedgeDefault'      => $config->wedgeDecision($sr->candidate->sizeBytes, $alreadyFree),
        ];

        return $row;
    }

    /**
     * The ranking criteria for a MAM search — replicates the (private)
     * MamFulfillment::policyFor() so the interactive panel ranks exactly like the
     * auto pipeline: MAM's own seed floor, the shared Prowlarr size cap and axis
     * weights, and a format rank matching the plan's content type.
     */
    private function mamMatchPolicy(ReleaseSearchPlan $plan): TorrentMatchPolicy
    {
        $shared = $this->integrations->getProwlarrConfig()->matchPolicy();

        return new TorrentMatchPolicy(
            minSeeders: $this->mamSettings->getMyAnonamouseConfig()->minSeeders,
            maxSizeBytes: $shared->maxSizeBytes,
            weights: $shared->weights,
            formatRank: $plan->contentType === ReleaseCandidate::CONTENT_AUDIOBOOK
                ? TorrentMatchPolicy::FORMAT_RANK
                : TorrentMatchPolicy::EBOOK_FORMAT_RANK,
        );
    }

    /**
     * Best-effort human-facing MAM browse URL for the query the panel just ran —
     * the on-site equivalent of the JSON search, mirroring the mirror sources'
     * `searchUrl`. Null when there is nothing to search for (then the panel simply
     * shows no query link, like the torrent source).
     */
    private static function mamSearchUrl(ReleaseSearchPlan $plan, string $baseUrl): ?string
    {
        $text = $plan->primaryQuery();
        if ($text === '' && $plan->hasIsbn()) {
            $text = $plan->isbnCandidates[0];
        }
        if ($text === '') {
            return null;
        }

        return rtrim($baseUrl, '/') . '/tor/browse.php?' . http_build_query(['tor' => ['text' => $text]]);
    }

    /**
     * The Best Match policy's format allow-list, so the panel can flag results
     * whose format the automatic pipeline would reject. Empty = allow all.
     *
     * @return list<string>
     */
    private function acceptedFormats(): array
    {
        return $this->integrations->getBestMatchPolicy()->formatPriority;
    }

    /**
     * Send one user-picked torrent to the download client, through the same grab
     * step the automatic pipeline uses. Returns as soon as the client accepts the
     * magnet — the async torrent poller finalizes the job and mirrors its status
     * onto the request.
     */
    #[Route('/interactive-search/grab', name: 'interactive_search_grab', methods: ['POST'])]
    public function grab(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);

        // MAM grabs branch off before the torrent path, which stays untouched:
        // torrent rows post no `source`, MAM rows post source=mam.
        if (trim((string) ($payload['source'] ?? '')) === DirectDownloadSource::Mam->value) {
            return $this->grabMam($payload);
        }

        $book = $this->resolveBook($payload);
        if ($book === null) {
            return $this->json(['error' => 'Could not resolve the book to download.'], 400);
        }

        $link = (string) ($this->blankToNull($payload['link'] ?? null) ?? '');
        $title = (string) ($this->blankToNull($payload['title'] ?? null) ?? '');
        if ($link === '' || $title === '') {
            return $this->json(['error' => 'This result has no download link.'], 400);
        }

        if (!$this->torrents->isAvailable()) {
            return $this->json(['error' => 'Torrent downloading is not configured.'], 409);
        }

        $audiobook = self::resolveAudiobook($payload, static fn (): ?Book => $book);

        /** @var User $user */
        $user = $this->getUser();
        // Book and audiobook are independent requests for the same work, so the
        // lookup is scoped to the edition this grab is for (RequestsController::create).
        $bookRequest = $this->requests->findOneByUserAndBook($user, $book, $audiobook);
        if ($bookRequest === null) {
            $bookRequest = new BookRequest($user, $book);
            $bookRequest->setAudiobook($audiobook);
            $this->em->persist($bookRequest);
        }
        $bookRequest->setStatus(BookRequest::STATUS_APPROVED);

        if ($bookRequest->getId() !== null && $this->jobs->hasActiveJobForRequest($bookRequest)) {
            return $this->json(['error' => 'A download is already in progress for this book.'], 409);
        }

        $sourceId = (string) ($this->blankToNull($payload['id'] ?? null) ?? $link);
        $job = new DownloadJob(
            source: DirectDownloadSource::Torrent->value,
            sourceId: mb_substr($sourceId, 0, 255),
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            bookRequest: $bookRequest,
        );
        $job->setStatus(DownloadJob::STATUS_QUEUED);
        $bookRequest->setDeliveryStatus(DownloadJob::STATUS_QUEUED);
        $this->em->persist($job);

        $candidate = new ReleaseCandidate(
            source: 'prowlarr',
            sourceId: $sourceId,
            title: $title,
            format: $this->blankToNull($payload['format'] ?? null),
            sizeBytes: isset($payload['sizeBytes']) && is_numeric($payload['sizeBytes']) ? (int) $payload['sizeBytes'] : null,
            downloadUrl: $link,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: $this->blankToNull($payload['indexer'] ?? null),
            seeders: isset($payload['seeders']) && is_numeric($payload['seeders']) ? (int) $payload['seeders'] : null,
            contentType: $audiobook ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK,
        );

        // Soft failure at 200, matching download(): the panel renders the message
        // inline instead of treating it as a transport error.
        try {
            $added = $this->torrents->grab($job, $candidate, $book->getTitle());
            $error = $added ? null : 'No torrent download client is configured.';
        } catch (\Throwable $e) {
            $error = 'Download client add failed: ' . $e->getMessage();
        }

        if ($error !== null) {
            $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($error);
            $bookRequest->setDeliveryStatus(DownloadJob::STATUS_ERROR);
            $this->em->flush();
            $this->logger->warning('Interactive torrent grab failed', ['book' => $book->getId(), 'error' => $error]);

            return $this->json(['ok' => false, 'queued' => false, 'error' => $error]);
        }

        $this->em->flush();
        $this->logger->info('Interactive torrent grab queued', [
            'book' => $book->getId(), 'job' => $job->getId(), 'hash' => $job->getClientRef(),
        ]);

        return $this->json([
            'ok'     => true,
            'queued' => true,
            'jobId'  => $job->getId(),
            'error'  => null,
        ]);
    }

    /**
     * The MAM half of grab(): the same book/request/job scaffolding as the torrent
     * path, but the job is stamped source=mam and the release goes through
     * MamFulfillment — which optionally spends a personal-freeleech wedge, fetches
     * the .torrent bytes off the row's dl hash, and hands them to the torrent
     * client. The posted wedge choice is re-validated server-side: never spend on
     * a release that is already free for this account, always spend when the
     * operator switched "always use wedge" on.
     *
     * @param array<string, mixed> $payload
     */
    private function grabMam(array $payload): JsonResponse
    {
        $book = $this->resolveBook($payload);
        if ($book === null) {
            return $this->json(['error' => 'Could not resolve the book to download.'], 400);
        }

        $title = (string) ($this->blankToNull($payload['title'] ?? null) ?? '');
        $mam = is_array($payload['mam'] ?? null) ? $payload['mam'] : [];
        $torrentId = is_numeric($mam['torrentId'] ?? null) ? (int) $mam['torrentId'] : 0;
        $dlHash = is_string($mam['dlHash'] ?? null) ? trim($mam['dlHash']) : '';
        if ($title === '' || $torrentId <= 0 || $dlHash === '') {
            return $this->json(['error' => 'This result is missing its MyAnonamouse download data.'], 400);
        }

        if (!$this->mamFulfillment->isAvailable()) {
            return $this->json(['error' => 'MyAnonamouse downloading is not configured.'], 409);
        }

        $audiobook = self::resolveAudiobook($payload, static fn (): ?Book => $book);

        /** @var User $user */
        $user = $this->getUser();
        // Book and audiobook are independent requests for the same work, so the
        // lookup is scoped to the edition this grab is for (RequestsController::create).
        $bookRequest = $this->requests->findOneByUserAndBook($user, $book, $audiobook);
        if ($bookRequest === null) {
            $bookRequest = new BookRequest($user, $book);
            $bookRequest->setAudiobook($audiobook);
            $this->em->persist($bookRequest);
        }
        $bookRequest->setStatus(BookRequest::STATUS_APPROVED);

        if ($bookRequest->getId() !== null && $this->jobs->hasActiveJobForRequest($bookRequest)) {
            return $this->json(['error' => 'A download is already in progress for this book.'], 409);
        }

        $config = $this->mamSettings->getMyAnonamouseConfig();
        $free = (bool) filter_var($mam['free'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $flVip = (bool) filter_var($mam['flVip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $personal = (bool) filter_var($mam['personalFreeleech'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $userIsVip = (bool) ($this->mamSettings->getMamAccountState()['isVip'] ?? false);
        $alreadyFree = $free || $personal || ($userIsVip && $flVip);

        // The panel's checkbox is only a request — recomputed here so a stale or
        // forged payload can neither waste a wedge on a free release nor dodge
        // the operator's "always use wedge" setting.
        $useWedge = (bool) filter_var($payload['useWedge'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($alreadyFree) {
            $useWedge = false;
        } elseif ($config->alwaysUseWedge) {
            $useWedge = true;
        }

        $link = (string) ($this->blankToNull($payload['link'] ?? null)
            ?? rtrim($config->baseUrl, '/') . '/tor/download.php/' . $dlHash);

        $job = new DownloadJob(
            source: DirectDownloadSource::Mam->value,
            sourceId: (string) $torrentId,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            bookRequest: $bookRequest,
        );
        $job->setStatus(DownloadJob::STATUS_QUEUED);
        $bookRequest->setDeliveryStatus(DownloadJob::STATUS_QUEUED);
        $this->em->persist($job);

        $candidate = new ReleaseCandidate(
            source: DirectDownloadSource::Mam->value,
            sourceId: (string) $torrentId,
            title: $title,
            format: $this->blankToNull($payload['format'] ?? null),
            sizeBytes: isset($payload['sizeBytes']) && is_numeric($payload['sizeBytes']) ? (int) $payload['sizeBytes'] : null,
            downloadUrl: $link,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: MamCandidateMapper::INDEXER,
            seeders: isset($payload['seeders']) && is_numeric($payload['seeders']) ? (int) $payload['seeders'] : null,
            contentType: $audiobook ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK,
            extra: [
                'mam' => [
                    'torrentId'         => $torrentId,
                    'dlHash'            => $dlHash,
                    'free'              => $free,
                    'flVip'             => $flVip,
                    'personalFreeleech' => $personal,
                ],
            ],
        );

        // Soft failure at 200, matching the torrent path: the panel renders the
        // message inline instead of treating it as a transport error.
        try {
            $added = $this->mamFulfillment->grab($job, $candidate, $book->getTitle(), $useWedge);
            $error = $added ? null : 'No torrent download client is configured.';
        } catch (\Throwable $e) {
            $error = 'Download client add failed: ' . $e->getMessage();
        }

        if ($error !== null) {
            $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($error);
            $bookRequest->setDeliveryStatus(DownloadJob::STATUS_ERROR);
            $this->em->flush();
            $this->logger->warning('Interactive MyAnonamouse grab failed', ['book' => $book->getId(), 'error' => $error]);

            return $this->json(['ok' => false, 'queued' => false, 'error' => $error]);
        }

        $this->em->flush();
        $this->logger->info('Interactive MyAnonamouse grab queued', [
            'book' => $book->getId(), 'job' => $job->getId(), 'hash' => $job->getClientRef(), 'wedge' => $useWedge,
        ]);

        return $this->json([
            'ok'     => true,
            'queued' => true,
            'jobId'  => $job->getId(),
            'error'  => null,
        ]);
    }

    /**
     * Download one user-chosen candidate into the library: stage the file via the
     * download client (to the staging/temp dir), rewrite its embedded metadata,
     * render the filename from the operator's template, move it into the output
     * folder, and mark the book Downloaded. Mirrors ProcessDownloadJobHandler for
     * a single candidate's links.
     */
    #[Route('/interactive-search/download', name: 'interactive_search_download', methods: ['POST'])]
    public function download(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);

        $book = $this->resolveBook($payload);
        if ($book === null) {
            return $this->json(['error' => 'Could not resolve the book to download.'], 400);
        }

        $links = array_values(array_filter(
            (array) ($payload['links'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        if ($links === []) {
            return $this->json(['error' => 'This result has no download links.'], 400);
        }

        $sourceId = trim((string) ($payload['source'] ?? ''));
        $format = $this->blankToNull($payload['format'] ?? null);
        $audiobook = self::resolveAudiobook($payload, static fn (): ?Book => $book);
        $subject = $book->getTitle();

        $client = $this->httpClient();
        if ($client === null) {
            return $this->json(['error' => 'No HTTP download client is configured.'], 500);
        }

        $config = $this->probe->config();
        if (trim($config->outputDirectory) === '') {
            return $this->json(['error' => 'No ebook library / watch folder configured in Settings → Ebooks.'], 409);
        }

        // Try each link in order; first one that stages a file wins (failover).
        $progress = new CollectingDownloadProgressReporter();
        $staged = null;
        $lastError = 'no link produced a downloadable file';
        foreach ($links as $url) {
            try {
                $id = $client->addDownload($url, $subject, ['progress' => $progress]);
                $status = $client->getStatus($id);
                if ($status->isComplete() && $status->filePath !== null) {
                    $staged = $status->filePath;
                    break;
                }
                $lastError = $status->message ?? 'download did not complete';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $progress->warn(sprintf('Link failed: %s', $lastError));
            }
        }

        if ($staged === null) {
            return $this->json([
                'ok'    => false,
                'error' => 'All links failed: ' . $lastError,
                'steps' => $progress->entries(),
            ]);
        }

        // Best-effort: rewrite embedded metadata before the file lands in the library.
        if ($this->metadataInjector->inject($staged, $book, $format)) {
            $progress->step('Rewrote embedded metadata from Spine Scout');
        }

        $filename = $this->filenames->render($config->filenameTemplate, $this->tokens($book, $format), $format);

        try {
            $finalPath = $this->mover->move($staged, $config->outputDirectory, $filename);
        } catch (\Throwable $e) {
            @unlink($staged);

            return $this->json([
                'ok'    => false,
                'error' => 'Move to output folder failed: ' . $e->getMessage(),
                'steps' => $progress->entries(),
            ]);
        }

        $bytes = @filesize($finalPath) ?: null;
        $this->recordDownload($book, $sourceId, $links, $finalPath, $format, $bytes, $audiobook);
        $progress->step('Downloaded → ' . basename($finalPath) . ' (awaiting library import)');
        $this->logger->info('Interactive manual download complete', [
            'book' => $book->getId(), 'path' => $finalPath, 'source' => $sourceId,
        ]);

        return $this->json([
            'ok'       => true,
            'bookId'   => $book->getId(),
            'filename' => basename($finalPath),
            'bytes'    => $bytes,
            'steps'    => $progress->entries(),
            'error'    => null,
        ]);
    }

    /**
     * Persist the successful download exactly as the automatic pipeline does on
     * success (ProcessDownloadJobHandler): a completed DownloadJob plus a request
     * for this user marked APPROVED + delivery COMPLETE — the "Downloaded"
     * (fetched, awaiting library import) state. Intentionally does NOT set
     * Book::downloaded, which is reserved for "In Library" (imported).
     *
     * @param list<string> $links
     */
    private function recordDownload(Book $book, string $sourceId, array $links, string $finalPath, ?string $format, ?int $bytes, bool $audiobook): void
    {
        /** @var User $user */
        $user = $this->getUser();
        // Book and audiobook are independent requests for the same work, so the
        // lookup is scoped to the edition this download fulfilled — an ebook
        // download must never complete the audiobook request (or vice-versa).
        $request = $this->requests->findOneByUserAndBook($user, $book, $audiobook);
        if ($request === null) {
            $request = new BookRequest($user, $book);
            $request->setAudiobook($audiobook);
            $this->em->persist($request);
        }
        $request->setStatus(BookRequest::STATUS_APPROVED)->setDeliveryStatus(DownloadJob::STATUS_COMPLETE);

        $job = new DownloadJob(
            $sourceId !== '' ? $sourceId : 'manual',
            (string) ($book->getId() ?? $book->getExternalId()),
            ReleaseCandidate::PROTOCOL_HTTP,
            $request,
        );
        $job->setFormat($format)
            ->setSizeBytes($bytes)
            ->setCandidateLinks($links)
            ->setDownloadUrl($links[0] ?? null)
            ->setFilePath($finalPath)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100);

        $this->em->persist($job);
        $this->em->flush();
    }

    /** @param array<string, mixed> $payload */
    private function resolveBook(array $payload): ?Book
    {
        $rawId = $payload['bookId'] ?? null;
        if (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) {
            $book = $this->books->find((int) $rawId);
            if ($book !== null) {
                return $book;
            }
        }

        $source = isset($payload['bookSource']) ? (string) $payload['bookSource'] : '';
        $externalId = isset($payload['externalId']) ? (string) $payload['externalId'] : '';
        if ($source === '' || $externalId === ''
            || !in_array($source, [Book::SOURCE_GRIMMORY, Book::SOURCE_HARDCOVER, Book::SOURCE_OPENLIBRARY], true)) {
            return null;
        }

        return $this->metadata->loadBySourceAndExternalId($source, $externalId, [
            'title'  => isset($payload['title']) ? (string) $payload['title'] : null,
            'author' => isset($payload['author']) ? (string) $payload['author'] : null,
        ]);
    }

    /**
     * Filename tokens from the (possibly user-edited) book metadata, mirroring
     * ProcessDownloadJobHandler::tokens().
     *
     * @return array<string, string|null>
     */
    private function tokens(Book $book, ?string $format): array
    {
        $year = null;
        if ($book->getPublishedDate() !== null && preg_match('/(\d{4})/', $book->getPublishedDate(), $m)) {
            $year = $m[1];
        }

        return [
            'author' => $book->getAuthor(),
            'title'  => $book->getTitle(),
            'year'   => $year,
            'isbn'   => $book->getIsbn() ?? ($book->getIsbns()[0] ?? null),
            'format' => $format,
        ];
    }

    private function httpClient(): ?DownloadClientInterface
    {
        foreach ($this->downloadClients as $client) {
            if ($client->getProtocol() === ReleaseCandidate::PROTOCOL_HTTP && $client->isConfigured()) {
                return $client;
            }
        }

        return null;
    }

    /** @return array<string, string|int|float|bool|list<string>|null> */
    private static function row(ScoredCandidate $sc): array
    {
        $c = $sc->candidate;
        $size = $c->extra['size'] ?? null;

        return [
            'id'        => $c->sourceId,
            'title'     => $c->title,
            'author'    => $c->author,
            'format'    => $c->format,
            'language'  => $c->language,
            'publisher' => $c->publisher,
            'year'      => $c->year,
            'size'      => is_string($size) ? $size : null,
            'infoUrl'   => $c->infoUrl,
            'isbns'     => $c->isbns,
            'matchPct'  => $sc->score->total,
            'qualifies' => $sc->qualifies,
            'links'     => $sc->detailLinks,
            // What this row actually is (classified from its file extension by the
            // source), mirroring the torrent rows' `torrent.type` label.
            'type'      => $c->contentType,
        ];
    }

    /**
     * One ranked torrent release in the same row shape the mirror sources emit,
     * plus a `torrent` block with the swarm facts the panel shows. `$planContentType`
     * is what we actually searched for, and stands in whenever a row's own categories
     * don't say — the panel always gets an audiobook/ebook label to render.
     *
     * @return array<string, mixed>
     */
    private static function torrentRow(ScoredRelease $sr, int $threshold, string $planContentType): array
    {
        $c = $sr->candidate;
        $leechers = $c->extra['leechers'] ?? null;
        $flags = $c->extra['flags'] ?? [];
        $type = $c->extra['type'] ?? null;
        $categories = $c->extra['categories'] ?? [];
        $published = $c->extra['publishDate'] ?? null;
        $matchPct = self::matchPct($sr);
        $link = trim((string) ($c->downloadUrl ?? ''));

        return [
            'id'        => $c->sourceId,
            'title'     => $c->title,
            'author'    => $c->author,
            'format'    => $c->format,
            'language'  => $c->language,
            'publisher' => $c->publisher,
            'year'      => $c->year,
            'size'      => self::humanBytes($c->sizeBytes),
            'sizeBytes' => $c->sizeBytes,
            'infoUrl'   => $c->infoUrl,
            'isbns'     => $c->isbns,
            'matchPct'  => $matchPct,
            'qualifies' => $matchPct >= $threshold,
            'links'     => $link !== '' ? [$link] : [],
            'torrent'   => [
                'indexer'  => $c->indexer,
                'seeders'  => $c->seeders,
                'leechers' => is_numeric($leechers) ? (int) $leechers : null,
                'grabs'    => $c->downloads,
                'flags'    => is_array($flags) ? array_values(array_filter($flags, 'is_string')) : [],
                'score'    => (int) round($sr->score * 100),
                'type'     => is_string($type) && $type !== '' ? $type : $planContentType,
                // Capped: indexers can tag a release with a whole category tree and
                // the panel only has room for a couple of chips.
                'categories' => is_array($categories)
                    ? array_slice(array_values(array_filter($categories, 'is_string')), 0, self::MAX_CATEGORIES)
                    : [],
                'published' => is_string($published) && $published !== '' ? $published : null,
            ],
        ];
    }

    /**
     * The title-match axis as a 0-100 percentage, so a torrent row is directly
     * comparable to the direct-download match threshold. Tolerant of the component
     * being expressed either as a 0..1 fraction or as an already-scaled percentage.
     */
    private static function matchPct(ScoredRelease $sr): int
    {
        $match = (float) ($sr->components['match'] ?? 0.0);

        return (int) round($match <= 1.0 ? $match * 100 : $match);
    }

    /**
     * True when this book is the audiobook edition rather than the ebook — the same
     * distinction RequestsController routes on (audiobooks go to the torrent
     * pipeline) and the format field Book documents for owned audio.
     */
    private static function isAudiobook(Book $book): bool
    {
        return AudioFormat::isAudio($book->getFormat());
    }

    /**
     * Whether this interactive-search action targets the audiobook edition.
     *
     * The payload's explicit `audiobook` flag (the panel's format toggle) wins
     * whenever it is present — parsed tolerantly (true/false, 1/0, '1'/'0',
     * 'true'/'false'). Absent or unparseable, fall back to the legacy derivation
     * from the owned copy's format via isAudiobook() — which reads "ebook" for
     * any book without an owned audio file, including a null book.
     *
     * @param array<string, mixed>    $payload
     * @param \Closure(): (Book|null) $book    resolved lazily — only invoked when
     *                                         the payload doesn't say
     */
    private static function resolveAudiobook(array $payload, \Closure $book): bool
    {
        if (\array_key_exists('audiobook', $payload) && $payload['audiobook'] !== null) {
            $parsed = filter_var($payload['audiobook'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $resolved = $book();

        return $resolved !== null && self::isAudiobook($resolved);
    }

    /** Indexer sizes are raw byte counts; the mirror sources already ship a string. */
    private static function humanBytes(?int $bytes): ?string
    {
        if ($bytes === null || $bytes < 0) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return $i === 0
            ? sprintf('%d %s', (int) $value, $units[$i])
            : sprintf('%.1f %s', $value, $units[$i]);
    }

    private function guardCsrf(Request $request): ?JsonResponse
    {
        $payload = $this->payload($request);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) ($payload['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
