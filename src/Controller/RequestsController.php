<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Service\AppSettingsProvider;
use App\Service\BookMetadataService;
use App\Download\Client\QbittorrentDownloadClient;
use App\Download\Client\TorrentClientSettings;
use App\Download\FulfillmentLog;
use App\Download\Torrent\TorrentFinalizerInterface;
use App\Integration\Prowlarr\ProwlarrClient;
use App\Message\DispatchReleaseSearch;
use App\Message\DispatchTorrentSearch;
use App\Message\PollTorrentJobs;
use App\Message\ReimportDownloadJob;
use App\Message\RewriteAudiobookSidecar;
use App\Repository\DownloadJobRepository;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class RequestsController extends AbstractController
{
    /** Long TTL; the proxy URL is deterministic per remote URL so it's safe to keep around. */
    private const COVER_CACHE_TTL = 60 * 60 * 24 * 30;

    /** CSRF id for the manual-fulfillment JSON endpoint on this page. */
    private const PIPELINE_CSRF_ID = 'requests_pipeline';

    /**
     * The distinct statuses a request shows on this page, in display order, keyed
     * by the filter key. Drives both the status badge and the status filter bar so
     * the two never diverge. Note these are *display* statuses: 'downloaded' is the
     * approved+delivery-complete combination, not a stored status.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'available'  => 'In Library',
        'downloaded' => 'Downloaded',
        'approved'   => 'Approved',
        'pending'    => 'Pending',
        'rejected'   => 'Rejected',
    ];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ProwlarrClient $prowlarr,
        private readonly QbittorrentDownloadClient $qbittorrent,
        private readonly TorrentClientSettings $torrentSettings,
    ) {
    }

    /** Memo for {@see torrentDeletePromptReady()} — one settings read per request, not per row. */
    private ?bool $torrentDeletePromptReady = null;

    /**
     * Whether deleting a request with an active torrent should offer the
     * keep-seeding / remove-from-client choice at all: the Settings → Torrents
     * ask-what-to-do toggle is on and the download client is configured. Gates
     * only the row's popup hook — with the toggle off the delete endpoint applies
     * the configured default action itself, without asking.
     */
    private function torrentDeletePromptReady(): bool
    {
        return $this->torrentDeletePromptReady ??=
            $this->torrentSettings->getTorrentClientConfig()->deletePromptEnabled
            && $this->qbittorrent->isConfigured();
    }

    /**
     * Whether the request's latest job is a torrent still expected to be present in
     * the download client: torrent protocol, a known hash, and either downloading
     * or complete (complete = finished and left seeding until/unless removed).
     */
    private static function isActiveTorrentJob(?DownloadJob $job): bool
    {
        return $job !== null
            && $job->getProtocol() === ReleaseCandidate::PROTOCOL_TORRENT
            && $job->getClientRef() !== null
            && in_array($job->getStatus(), [DownloadJob::STATUS_DOWNLOADING, DownloadJob::STATUS_COMPLETE], true);
    }

    /**
     * Route a just-approved request to the right fulfillment pipeline: audiobooks
     * go to Prowlarr/qBittorrent (torrent), everything else to the direct-download
     * cascade (HTTP).
     */
    private function dispatchFulfillment(BookRequest $entity, MessageBusInterface $bus): void
    {
        if ($entity->isAudiobook()) {
            $bus->dispatch(new DispatchTorrentSearch((int) $entity->getId()));
        } else {
            $bus->dispatch(new DispatchReleaseSearch((int) $entity->getId()));
        }
    }

    /** Both halves of the audiobook torrent stack must be configured to fulfill one. */
    private function torrentStackReady(): bool
    {
        return $this->prowlarr->isConfigured() && $this->qbittorrent->isConfigured();
    }

    /**
     * Requests rendered per page. Server-side paging keeps the render cost (and the
     * per-row cover resolution) bounded no matter how long the request history gets.
     */
    private const PER_PAGE = 50;

    #[Route('/requests', name: 'requests', methods: ['GET'])]
    public function index(Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, SearchSettingsProvider $settings): Response
    {
        $total = $requests->countForList();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamp rather than 404: a stale ?page= from a bookmark or after deletions
        // should land on a real page, not an error.
        // Cast rather than getInt(): a non-numeric ?page= is user input, not an error —
        // getInt() would throw on it, while (int) lands on 0 and clamps to the first page.
        $page = min($pages, max(1, (int) $request->query->get('page', 1)));
        $rows = $requests->findAllForList($page, self::PER_PAGE);

        $ids = [];
        foreach ($rows as $r) {
            if ($r->getId() !== null) {
                $ids[] = $r->getId();
            }
        }
        $latestJobs = $jobs->latestByRequestIds($ids);

        $now = new \DateTimeImmutable();
        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->buildItem($r, $latestJobs[$r->getId()] ?? null, $metadata, $now);
        }

        // The endless-scroll fetch: the same page query, answered as rendered row
        // HTML for the client to append instead of a full document.
        if (self::wantsJson($request)) {
            $html = '';
            foreach ($items as $item) {
                $html .= $this->renderView('requests/_row.html.twig', ['item' => $item]);
            }

            return new JsonResponse([
                'ok'    => true,
                'rows'  => $html,
                'page'  => $page,
                'pages' => $pages,
                'total' => $total,
            ]);
        }

        return $this->render('requests/index.html.twig', [
            'items'                  => $items,
            'filters'                => $this->buildFilters($items),
            'format_filters'         => $this->buildFormatFilters($items),
            'automatic_fulfillment'  => $settings->isAutomaticFulfillmentEnabled(),
            'page'                   => $page,
            'pages'                  => $pages,
            'total'                  => $total,
        ]);
    }

    /**
     * The next request awaiting manual fulfillment, oldest first — the queue the
     * requests-page interactive-search overlay walks when automatic fulfillment is
     * off. `after` lets the client advance past the item it just handled.
     *
     * "Awaiting manual fulfillment" is exactly the set
     * {@see BookRequestRepository::findApprovedNeedingSearch()} returns: approved,
     * with no download job that is in-flight (queued/resolving/downloading) or
     * already complete. Never-started and errored/cancelled requests are in;
     * delivered and in-progress ones are out. Reusing that query keeps this queue
     * and the automatic retry sweep on one definition.
     */
    #[Route('/requests/manual-next', name: 'requests_manual_next', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function manualNext(Request $request, BookRequestRepository $requests): JsonResponse
    {
        $payload = self::jsonPayload($request);
        if (($error = $this->guardPipelineCsrf($payload)) !== null) {
            return $error;
        }

        $after = isset($payload['after']) && is_numeric($payload['after']) ? (int) $payload['after'] : null;

        $candidates = $requests->findApprovedNeedingSearch();
        // Page by id so "next" is stable and monotonic even when two requests share
        // a createdAt second; id order is creation order.
        usort($candidates, static fn (BookRequest $a, BookRequest $b): int => (int) $a->getId() <=> (int) $b->getId());

        foreach ($candidates as $candidate) {
            $id = (int) $candidate->getId();
            if ($after !== null && $id <= $after) {
                continue;
            }

            $book = $candidate->getBook();

            return new JsonResponse([
                'done'    => false,
                'request' => [
                    'id'          => $id,
                    'bookId'      => $book->getId(),
                    'title'       => $book->getTitle(),
                    'author'      => $book->getAuthor(),
                    'isbn'        => $book->getIsbn(),
                    'bookSource'  => $book->getSource(),
                    'externalId'  => $book->getExternalId(),
                    'audiobook'   => $candidate->isAudiobook(),
                    'requestedBy' => $candidate->getRequestedBy()->getUsername(),
                ],
            ]);
        }

        return new JsonResponse(['done' => true]);
    }

    /** @return array<string, mixed> */
    private static function jsonPayload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function guardPipelineCsrf(array $payload): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::PIPELINE_CSRF_ID, (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        return null;
    }

    /**
     * The view-model array `requests/_row.html.twig` renders — built the same way
     * for the initial page and for the JSON action responses that re-render a
     * single row in place.
     *
     * @return array<string, mixed>
     */
    private function buildItem(BookRequest $r, ?DownloadJob $job, BookMetadataService $metadata, \DateTimeImmutable $now): array
    {
        $staleBefore = $now->modify('-' . DownloadJobRepository::STALE_AFTER_SECONDS . ' seconds');
        // A job idle in an in-flight state past the stale window is orphaned
        // (worker died mid-download) — surface a re-check so it's recoverable.
        $stalled = $job !== null
            && in_array($job->getStatus(), DownloadJob::ACTIVE_STATUSES, true)
            && $job->getUpdatedAt() < $staleBefore;
        // A completed job is terminal, so its updatedAt is effectively the
        // moment the download finished — surface it as "Downloaded … ago".
        $downloadedAt = $job !== null && $job->getStatus() === DownloadJob::STATUS_COMPLETE
            ? $job->getUpdatedAt()
            : null;
        $statusKey = self::displayStatusKey($r);

        return [
            'entity'         => $r,
            'ago'            => self::humanAgo($now, $r->getCreatedAt()),
            'downloaded_at'  => $downloadedAt,
            'downloaded_ago' => $downloadedAt !== null ? self::humanAgo($now, $downloadedAt) : null,
            'cover_url'      => $metadata->ensureCoverProxyUrl($r->getBook()),
            'job'            => $job,
            'stalled'        => $stalled,
            'status_key'     => $statusKey,
            'status_label'   => self::STATUS_LABELS[$statusKey] ?? $r->getStatusLabel(),
            'format_key'     => $r->isAudiobook() ? 'audiobook' : 'book',
            // Drives the delete-form popup hook: only when the latest job's torrent
            // should still be in the client AND the operator toggle allows asking.
            'torrent_active' => self::isActiveTorrentJob($job) && $this->torrentDeletePromptReady(),
            'torrent_state'  => $job !== null && $job->getStatus() === DownloadJob::STATUS_COMPLETE ? 'seeding' : 'downloading',
        ];
    }

    /** Whether the client asked for a JSON answer (the request-actions fetch) instead of the redirect flow. */
    private static function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    /**
     * The JSON answer to a successful row action: a toast message plus the row's
     * re-rendered HTML so the client can swap it in place with fresh status,
     * buttons and CSRF tokens.
     */
    private function rowActionResponse(BookRequest $entity, string $message, DownloadJobRepository $jobs, BookMetadataService $metadata): JsonResponse
    {
        $item = $this->buildItem($entity, $jobs->findLatestForRequest($entity), $metadata, new \DateTimeImmutable());

        return new JsonResponse([
            'ok'      => true,
            'message' => $message,
            'row'     => $this->renderView('requests/_row.html.twig', ['item' => $item]),
        ]);
    }

    /**
     * The display status key for a request — the same derivation the badge uses:
     * 'available' → In Library, approved + delivery complete → 'downloaded', else
     * the stored status ('pending' | 'approved' | 'rejected').
     */
    private static function displayStatusKey(BookRequest $r): string
    {
        if ($r->getStatus() === BookRequest::STATUS_AVAILABLE) {
            return 'available';
        }
        if ($r->getStatus() === BookRequest::STATUS_APPROVED && $r->getDeliveryStatus() === DownloadJob::STATUS_COMPLETE) {
            return 'downloaded';
        }

        return $r->getStatus();
    }

    /**
     * Whether the request has been fulfilled into the library — the same predicate
     * `requests/_row.html.twig` uses for its `is_fulfilled` flag: marked available,
     * or approved with its latest delivery complete.
     */
    private static function isFulfilled(BookRequest $r): bool
    {
        return $r->getStatus() === BookRequest::STATUS_AVAILABLE
            || ($r->getStatus() === BookRequest::STATUS_APPROVED && $r->getDeliveryStatus() === DownloadJob::STATUS_COMPLETE);
    }

    /**
     * The filter chips to show: "All" plus one per display status actually present,
     * in canonical order, each with its count. Empty categories are omitted so the
     * bar only ever offers filters that match something.
     *
     * @param list<array{status_key: string, ...}> $items
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function buildFilters(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $key = $item['status_key'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $filters = [['key' => 'all', 'label' => 'All', 'count' => \count($items)]];
        foreach (self::STATUS_LABELS as $key => $label) {
            if (isset($counts[$key])) {
                $filters[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key]];
            }
        }

        return $filters;
    }

    /**
     * The format filter chips: "All" plus Book / Audiobook, each with its count.
     * A chip is omitted when nothing matches so the bar only offers useful filters
     * (and disappears entirely when every request is the same format).
     *
     * @param list<array{format_key: string, ...}> $items
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function buildFormatFilters(array $items): array
    {
        $counts = ['book' => 0, 'audiobook' => 0];
        foreach ($items as $item) {
            $counts[$item['format_key']] = ($counts[$item['format_key']] ?? 0) + 1;
        }

        // Only one format present → no useful choice, hide the bar.
        if ($counts['book'] === 0 || $counts['audiobook'] === 0) {
            return [];
        }

        return [
            ['key' => 'all', 'label' => 'All formats', 'count' => \count($items)],
            ['key' => 'book', 'label' => 'Book', 'count' => $counts['book']],
            ['key' => 'audiobook', 'label' => 'Audiobook', 'count' => $counts['audiobook']],
        ];
    }

    private function rememberCover(int $bookId, string $proxyUrl): void
    {
        $item = $this->cache->getItem('book.cover.' . $bookId);
        $item->set($proxyUrl);
        $item->expiresAfter(self::COVER_CACHE_TTL);
        $this->cache->save($item);
    }

    #[Route('/requests/create', name: 'requests_create', methods: ['POST'])]
    public function create(
        Request $request,
        BookRequestRepository $requests,
        BookRepository $books,
        BookMetadataService $metadata,
        EntityManagerInterface $em,
        AppSettingsProvider $settings,
        MessageBusInterface $bus,
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $token = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('book-request', $token)) {
            return new JsonResponse(['error' => 'invalid_csrf'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        $book = null;
        $rawId = $payload['bookId'] ?? null;
        if (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) {
            $book = $books->find((int) $rawId);
        }
        if ($book === null) {
            $source = isset($payload['source']) ? (string) $payload['source'] : '';
            $externalId = isset($payload['externalId']) ? (string) $payload['externalId'] : '';
            if ($source === '' || $externalId === '') {
                return new JsonResponse(['error' => 'missing_identifier'], 400);
            }
            if (!in_array($source, [Book::SOURCE_GRIMMORY, Book::SOURCE_HARDCOVER, Book::SOURCE_OPENLIBRARY], true)) {
                return new JsonResponse(['error' => 'unknown_source'], 400);
            }
            $book = $metadata->loadBySourceAndExternalId($source, $externalId, [
                'title' => isset($payload['title']) ? (string) $payload['title'] : null,
                'author' => isset($payload['author']) ? (string) $payload['author'] : null,
                'externalUrl' => isset($payload['externalUrl']) ? (string) $payload['externalUrl'] : null,
            ]);
        }

        $bookId = $book->getId();
        $coverUrl = isset($payload['coverUrl']) ? trim((string) $payload['coverUrl']) : '';
        if ($bookId !== null && $coverUrl !== '' && str_starts_with($coverUrl, '/cover/')) {
            $this->rememberCover($bookId, $coverUrl);
        }

        $audiobook = !empty($payload['audiobook']) && $payload['audiobook'] !== '0';

        // Book and audiobook are independent requests for the same work.
        $existing = $requests->findOneByUserAndBook($user, $book, $audiobook);
        if ($existing !== null) {
            return new JsonResponse([
                'requested' => true,
                'requestId' => $existing->getId(),
                'bookId' => $book->getId(),
                'audiobook' => $existing->isAudiobook(),
                'alreadyExisted' => true,
            ]);
        }

        $entity = new BookRequest($user, $book);
        $entity->setAudiobook($audiobook);
        // Auto-approve when enabled globally OR for this specific user. Audiobooks go to
        // the torrent pipeline, so only auto-approve one when Prowlarr + qBittorrent are
        // configured; otherwise it stays pending (an admin can approve once the stack is
        // set up) rather than erroring out immediately.
        $autoApproved = ($settings->isAutoApproveRequestsEnabled() || $user->isAutoApproveRequests())
            && (!$audiobook || $this->torrentStackReady());
        if ($autoApproved) {
            $entity->setStatus(BookRequest::STATUS_APPROVED);
        }
        $em->persist($entity);
        $em->flush();

        if ($autoApproved) {
            // Same async fulfillment loop a manual approve() triggers.
            $this->dispatchFulfillment($entity, $bus);
        }

        return new JsonResponse([
            'requested' => true,
            'requestId' => $entity->getId(),
            'bookId' => $book->getId(),
            'status' => $entity->getStatus(),
            'audiobook' => $entity->isAudiobook(),
            'alreadyExisted' => false,
        ]);
    }

    #[Route('/requests/{id}/approve', name: 'requests_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, EntityManagerInterface $em, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('approve-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $entity->setStatus(BookRequest::STATUS_APPROVED);
        $em->flush();

        // Kick off the async fulfillment loop: search → best match → download.
        $this->dispatchFulfillment($entity, $bus);

        if (self::wantsJson($request)) {
            return $this->rowActionResponse($entity, 'Approved — searching for a release…', $jobs, $metadata);
        }

        return $this->redirectToRoute('requests');
    }

    #[Route('/requests/{id}/recheck', name: 'requests_recheck', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function recheck(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('recheck-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        // Only approved requests are in the fulfillment pipeline. Cancel any
        // in-flight job first (it may be an orphaned/stalled one) so the dispatch —
        // idempotent via hasActiveJobForRequest() — can start a fresh attempt.
        $recheckable = $entity->getStatus() === BookRequest::STATUS_APPROVED;
        if ($recheckable) {
            $jobs->cancelActiveForRequest($entity);
            $this->dispatchFulfillment($entity, $bus);
        }

        if (self::wantsJson($request)) {
            if (!$recheckable) {
                return new JsonResponse(['ok' => false, 'message' => 'Only approved requests can be re-checked.'], 409);
            }

            return $this->rowActionResponse($entity, 'Re-checking for a release…', $jobs, $metadata);
        }

        if ($recheckable) {
            $this->addFlash('success', 'Re-checking for a release…');
        }

        return $this->redirectToRoute('requests');
    }

    /**
     * The torrents currently in the download client's category, for the manual
     * "Link torrent" picker. Each row carries a `linked` flag — true when the
     * hash is already tracked by an in-flight job — so the picker can grey it
     * out rather than offer to double-link one torrent to two requests.
     */
    #[Route('/requests/{id}/link-torrent/options', name: 'requests_link_torrent_options', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function linkTorrentOptions(int $id, BookRequestRepository $requests, DownloadJobRepository $jobs): JsonResponse
    {
        if ($requests->find($id) === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->qbittorrent->isConfigured()) {
            return new JsonResponse(['ok' => false, 'message' => 'Torrent download client is not configured.'], 409);
        }

        $linked = [];
        foreach ($jobs->activeTorrentJobs() as $job) {
            if ($job->getClientRef() !== null) {
                $linked[strtolower($job->getClientRef())] = true;
            }
        }

        $torrents = $this->qbittorrent->listDownloads();
        foreach ($torrents as &$torrent) {
            $torrent['linked'] = isset($linked[$torrent['id']]);
        }
        unset($torrent);

        // Incomplete torrents first (the likely link targets), then by name.
        usort($torrents, static fn (array $a, array $b): int => ($a['completed'] <=> $b['completed']) ?: strcasecmp($a['name'], $b['name']));

        return new JsonResponse(['ok' => true, 'torrents' => $torrents]);
    }

    /**
     * Manually link a request to a torrent already in the download client — the
     * rescue path for a grab the client accepted but whose job lost its hash
     * (or was added outside the app entirely). Creates a DOWNLOADING job carrying
     * the hash as its clientRef; the torrent poller then finalizes it into the
     * library exactly like an automatic grab.
     */
    #[Route('/requests/{id}/link-torrent', name: 'requests_link_torrent', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function linkTorrent(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, EntityManagerInterface $em, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('link-torrent-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $hash = strtolower((string) $request->request->get('hash'));
        if (preg_match('/^[a-f0-9]{40}$/', $hash) !== 1) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid torrent hash.'], 400);
        }
        if (!$this->qbittorrent->isConfigured()) {
            return new JsonResponse(['ok' => false, 'message' => 'Torrent download client is not configured.'], 409);
        }

        $torrent = null;
        foreach ($this->qbittorrent->listDownloads() as $row) {
            if ($row['id'] === $hash) {
                $torrent = $row;
                break;
            }
        }
        if ($torrent === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Torrent not found in the download client.'], 409);
        }
        if ($jobs->hasActiveJobForRequest($entity)) {
            return new JsonResponse(['ok' => false, 'message' => 'A download is already in progress for this request — cancel it first.'], 409);
        }

        // Linking implies approval, so a still-pending request enters the pipeline
        // here; other statuses are left alone.
        if ($entity->getStatus() === BookRequest::STATUS_PENDING) {
            $entity->setStatus(BookRequest::STATUS_APPROVED);
        }

        $job = new DownloadJob(
            source: 'torrent',
            sourceId: mb_substr('manual-link:' . $hash, 0, 255),
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            bookRequest: $entity,
        );
        $job->setClientRef($hash)
            ->setStatus(DownloadJob::STATUS_DOWNLOADING)
            ->setProgress((int) round($torrent['progress']))
            ->setStatusMessage('Manually linked to torrent "' . mb_substr($torrent['name'], 0, 120) . '".')
            ->setSizeBytes($torrent['sizeBytes']);
        $entity->setDeliveryStatus(DownloadJob::STATUS_DOWNLOADING);
        $em->persist($job);
        $em->flush();

        // An already-finished torrent (downloaded, now seeding) shouldn't wait for
        // the next scheduled tick: kick the poller now so it finalizes the job —
        // sanity checks, move into the library, sidecar/metadata — immediately.
        $alreadyComplete = !empty($torrent['completed']);
        if ($alreadyComplete) {
            $bus->dispatch(new PollTorrentJobs());
        }

        if (self::wantsJson($request)) {
            $message = $alreadyComplete
                ? 'Linked to torrent — already downloaded, importing into the library now…'
                : 'Linked to torrent — will import when the download completes.';

            return $this->rowActionResponse($entity, $message, $jobs, $metadata);
        }

        return $this->redirectToRoute('requests');
    }

    #[Route('/requests/{id}/rewrite-sidecar', name: 'requests_rewrite_sidecar', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rewriteSidecar(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('rewrite-sidecar-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        // Only a completed audiobook download has an on-disk album folder to rewrite
        // a sidecar beside; its path lives on the latest job's filePath.
        $job = $jobs->findLatestForRequest($entity);
        if (!$entity->isAudiobook() || $job === null || $job->getStatus() !== DownloadJob::STATUS_COMPLETE || $job->getFilePath() === null) {
            if (self::wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => 'No downloaded audiobook to rewrite for this request.'], 409);
            }
            $this->addFlash('error', 'No downloaded audiobook to rewrite for this request.');

            return $this->redirectToRoute('requests');
        }

        $bus->dispatch(new RewriteAudiobookSidecar((int) $job->getId()));

        if (self::wantsJson($request)) {
            return $this->rowActionResponse($entity, 'Metadata sidecar rewrite queued.', $jobs, $metadata);
        }
        $this->addFlash('success', 'Metadata sidecar rewrite queued.');

        return $this->redirectToRoute('requests');
    }

    /**
     * Queue a re-import of a fulfilled request's completed download into the
     * library. Only possible while the torrent's raw files are still in the
     * download client; otherwise answers a 409 carrying `unavailable: true` plus
     * the re-get options the client can offer instead (automatic re-download
     * and/or interactive search).
     */
    #[Route('/requests/{id}/reimport', name: 'requests_reimport', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_REIMPORT')]
    public function reimport(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, SearchSettingsProvider $settings, TorrentFinalizerInterface $finalizer, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('reimport-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        // Only a fulfilled request with a completed download has anything to re-import.
        $job = $jobs->findLatestForRequest($entity);
        if (!self::isFulfilled($entity) || $job === null || $job->getStatus() !== DownloadJob::STATUS_COMPLETE) {
            if (self::wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => 'No completed download to re-import for this request.'], 409);
            }
            $this->addFlash('error', 'No completed download to re-import for this request.');

            return $this->redirectToRoute('requests');
        }

        // The raw files only survive in the torrent client (a direct download is
        // consumed by its import); re-importable = torrent job + configured client
        // + the torrent still present with its files on disk.
        $sourceAvailable = $job->getProtocol() === ReleaseCandidate::PROTOCOL_TORRENT
            && $this->qbittorrent->isConfigured()
            && $finalizer->sourceAvailability($job, $this->qbittorrent) !== null;

        if ($sourceAvailable) {
            $bus->dispatch(new ReimportDownloadJob((int) $job->getId()));

            if (self::wantsJson($request)) {
                return $this->rowActionResponse($entity, 'Reimport queued: the files will be re-imported into the library.', $jobs, $metadata);
            }
            $this->addFlash('success', 'Reimport queued: the files will be re-imported into the library.');

            return $this->redirectToRoute('requests');
        }

        // Gone from the client — offer a re-get instead. `canAuto` mirrors what the
        // dispatch handlers require to actually act: automatic fulfillment on, and
        // (for an audiobook) the Prowlarr + qBittorrent stack configured.
        $canAuto = $settings->isAutomaticFulfillmentEnabled()
            && (!$entity->isAudiobook() || $this->torrentStackReady());
        $message = 'Original files are no longer available in the download client.';

        if (self::wantsJson($request)) {
            return new JsonResponse([
                'ok'          => false,
                'unavailable' => true,
                'canAuto'     => $canAuto,
                'canSearch'   => $this->isGranted(User::ROLE_INTERACTIVE_SEARCH),
                'message'     => $message,
            ], 409);
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute('requests');
    }

    /**
     * Re-fetch a fulfilled request from scratch — the fallback when the original
     * files are gone from the download client. Mirrors recheck()'s bookkeeping:
     * cancel any lingering active job, then re-enter the fulfillment pipeline.
     * The dispatch handlers only act on APPROVED requests, so an AVAILABLE one is
     * set back to APPROVED (it is being re-fetched), and the completed delivery
     * mirror is cleared so the row reflects the new attempt.
     */
    #[Route('/requests/{id}/reget', name: 'requests_reget', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_REIMPORT')]
    public function reget(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, EntityManagerInterface $em, MessageBusInterface $bus): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('reget-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        if (!self::isFulfilled($entity)) {
            if (self::wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => 'Only fulfilled requests can be re-downloaded.'], 409);
            }
            $this->addFlash('error', 'Only fulfilled requests can be re-downloaded.');

            return $this->redirectToRoute('requests');
        }

        $jobs->cancelActiveForRequest($entity);
        if ($entity->getStatus() === BookRequest::STATUS_AVAILABLE) {
            $entity->setStatus(BookRequest::STATUS_APPROVED);
        }
        $entity->setDeliveryStatus(null);
        $em->flush();

        $this->dispatchFulfillment($entity, $bus);

        if (self::wantsJson($request)) {
            return $this->rowActionResponse($entity, 'Re-download started.', $jobs, $metadata);
        }
        $this->addFlash('success', 'Re-download started.');

        return $this->redirectToRoute('requests');
    }

    #[Route('/requests/{id}/delete', name: 'requests_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, FulfillmentLog $fulfillmentLog, EntityManagerInterface $em): Response
    {
        $entity = $requests->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $isOwner = $entity->getRequestedBy()->getId() === $user->getId();
        if (!$user->isAdmin() && !$isOwner) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('delete-request-' . $id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        // Torrent-aware deletion: when the latest job's torrent should still be in
        // the download client (downloading or seeding), what happens to it follows
        // the Settings → Torrents choice. Prompt on: the user picks keep seeding
        // (re-tagged so the operator can spot it) or remove-with-files via the
        // dialog round-trip. Prompt off: the configured default action is applied
        // silently through the same code paths. No active torrent (or client not
        // configured) → plain delete, exactly as before.
        $job = $jobs->findLatestForRequest($entity);
        $config = $this->torrentSettings->getTorrentClientConfig();
        $hasActiveTorrent = self::isActiveTorrentJob($job) && $this->qbittorrent->isConfigured();
        $torrentAction = (string) $request->request->get('torrent_action', '');
        $message = 'Request removed.';

        if ($hasActiveTorrent && $config->deletePromptEnabled && $torrentAction === '' && self::wantsJson($request)) {
            // Prompt on, no decision yet — don't delete; ask the frontend to pop
            // the dialog and re-submit with a torrent_action. (A non-JSON post has
            // no way to show the dialog, so it falls through to a plain delete.)
            return new JsonResponse([
                'ok'                   => true,
                'needsTorrentDecision' => true,
                'torrentState'         => $job->getStatus() === DownloadJob::STATUS_COMPLETE ? 'seeding' : 'downloading',
            ]);
        }

        if ($hasActiveTorrent && !$config->deletePromptEnabled && !in_array($torrentAction, ['keep', 'remove'], true)) {
            // Prompt off — no one is asked; the operator's default action decides.
            $torrentAction = $config->deleteDefaultAction;
        }

        if ($hasActiveTorrent && in_array($torrentAction, ['keep', 'remove'], true)) {
            $hash = (string) $job->getClientRef();
            $title = $entity->getBook()->getTitle();
            try {
                if ($torrentAction === 'remove') {
                    $this->qbittorrent->deleteTorrent($hash, true);
                    $message = 'Request removed. Torrent deleted from the download client.';
                } else {
                    $tag = $config->releasedTag;
                    if ($tag !== '') {
                        $this->qbittorrent->addTags($hash, $tag);
                        $message = 'Request removed. Torrent kept seeding and tagged.';
                    }
                }
            } catch (\Throwable $e) {
                // The request deletion must not be blocked by a client hiccup —
                // proceed, but say (and log) that the torrent was left untouched.
                $message = 'Request removed. (Could not update the torrent: ' . $e->getMessage() . ')';
                $fulfillmentLog->warn(
                    'Request deletion could not ' . ($torrentAction === 'remove' ? 'remove torrent ' : 'tag torrent ') . $hash . ': ' . $e->getMessage(),
                    $title,
                );
            }
        }

        $em->remove($entity);
        $em->flush();

        if (self::wantsJson($request)) {
            return new JsonResponse(['ok' => true, 'message' => $message, 'removed' => true]);
        }

        return $this->redirectToRoute('requests');
    }

    private static function humanAgo(\DateTimeImmutable $now, \DateTimeImmutable $then): string
    {
        $diff = $now->getTimestamp() - $then->getTimestamp();
        if ($diff < 60)        return 'just now';
        if ($diff < 3600)      return self::pluralize(intdiv($diff, 60), 'minute') . ' ago';
        if ($diff < 86400)     return self::pluralize(intdiv($diff, 3600), 'hour') . ' ago';
        if ($diff < 86400 * 7) return self::pluralize(intdiv($diff, 86400), 'day') . ' ago';
        if ($diff < 86400 * 30)return self::pluralize(intdiv($diff, 86400 * 7), 'week') . ' ago';
        if ($diff < 86400 * 365) return self::pluralize(intdiv($diff, 86400 * 30), 'month') . ' ago';
        return self::pluralize(intdiv($diff, 86400 * 365), 'year') . ' ago';
    }

    private static function pluralize(int $n, string $unit): string
    {
        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }
}
