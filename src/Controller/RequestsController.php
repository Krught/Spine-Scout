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
use App\Integration\Prowlarr\ProwlarrClient;
use App\Message\DispatchReleaseSearch;
use App\Message\DispatchTorrentSearch;
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

    /** CSRF id shared by the manual-fulfillment JSON endpoints on this page. */
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
    ) {
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
     * Flip the automatic fulfillment pipeline on/off. Off means the dispatch
     * handlers no-op, so approving leaves a request APPROVED awaiting a manual
     * interactive search from this page.
     */
    #[Route('/requests/pipeline-toggle', name: 'requests_pipeline_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGE_SETTINGS')]
    public function pipelineToggle(Request $request, SearchSettingsProvider $settings): JsonResponse
    {
        $payload = self::jsonPayload($request);
        if (($error = $this->guardPipelineCsrf($payload)) !== null) {
            return $error;
        }

        $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $settings->setAutomaticFulfillmentEnabled($enabled);

        return new JsonResponse(['enabled' => $enabled]);
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
    public function linkTorrent(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata, EntityManagerInterface $em): Response
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

        if (self::wantsJson($request)) {
            return $this->rowActionResponse($entity, 'Linked to torrent — will import when the download completes.', $jobs, $metadata);
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

    #[Route('/requests/{id}/delete', name: 'requests_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, BookRequestRepository $requests, EntityManagerInterface $em): Response
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

        $em->remove($entity);
        $em->flush();

        if (self::wantsJson($request)) {
            return new JsonResponse(['ok' => true, 'message' => 'Request removed.', 'removed' => true]);
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
