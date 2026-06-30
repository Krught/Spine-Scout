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
use App\Repository\DownloadJobRepository;
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

    #[Route('/requests', name: 'requests', methods: ['GET'])]
    public function index(BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata): Response
    {
        $rows = $requests->findAllForList();

        $ids = [];
        foreach ($rows as $r) {
            if ($r->getId() !== null) {
                $ids[] = $r->getId();
            }
        }
        $latestJobs = $jobs->latestByRequestIds($ids);

        $now = new \DateTimeImmutable();
        $staleBefore = $now->modify('-' . DownloadJobRepository::STALE_AFTER_SECONDS . ' seconds');
        $items = [];
        foreach ($rows as $r) {
            $job = $latestJobs[$r->getId()] ?? null;
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
            $items[] = [
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

        return $this->render('requests/index.html.twig', [
            'items'          => $items,
            'filters'        => $this->buildFilters($items),
            'format_filters' => $this->buildFormatFilters($items),
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
        // Auto-approve when enabled. Audiobooks go to the torrent pipeline, so only
        // auto-approve one when Prowlarr + qBittorrent are configured; otherwise it
        // stays pending (an admin can approve once the stack is set up) rather than
        // erroring out immediately.
        $autoApproved = $settings->isAutoApproveRequestsEnabled()
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
    public function approve(int $id, Request $request, BookRequestRepository $requests, EntityManagerInterface $em, MessageBusInterface $bus): Response
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

        return $this->redirectToRoute('requests');
    }

    #[Route('/requests/{id}/recheck', name: 'requests_recheck', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function recheck(int $id, Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, MessageBusInterface $bus): Response
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
        if ($entity->getStatus() === BookRequest::STATUS_APPROVED) {
            $jobs->cancelActiveForRequest($entity);
            $this->dispatchFulfillment($entity, $bus);
            $this->addFlash('success', 'Re-checking for a release…');
        }

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
