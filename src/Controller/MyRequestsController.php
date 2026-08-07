<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use App\Service\BookMetadataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current user's own requests — the /requests list scoped to one requester.
 *
 * Row rendering is shared with the all-requests page (`requests/_row.html.twig`),
 * so the view-model built here deliberately mirrors RequestsController::buildItem()
 * key for key. The item-building helpers are duplicated rather than shared because
 * they are private to RequestsController; keep the two in sync if the row template's
 * expectations change.
 */
#[IsGranted('ROLE_USER')]
final class MyRequestsController extends AbstractController
{
    /** Requests rendered per page — mirrors RequestsController::PER_PAGE. */
    private const PER_PAGE = 50;

    /**
     * Display-status labels in canonical order, keyed by filter key — mirrors
     * RequestsController::STATUS_LABELS so the badge and chips never diverge
     * between the two pages.
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

    #[Route('/my-requests', name: 'my_requests', methods: ['GET'])]
    public function index(Request $request, BookRequestRepository $requests, DownloadJobRepository $jobs, BookMetadataService $metadata): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $total = $requests->countForList($user);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamp rather than 404, and cast rather than getInt(): a stale or junk
        // ?page= is user input that should land on a real page, not error out.
        $page = min($pages, max(1, (int) $request->query->get('page', 1)));
        $rows = $requests->findAllForList($page, self::PER_PAGE, $user);

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
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
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

        return $this->render('my_requests/index.html.twig', [
            'items'          => $items,
            'filters'        => $this->buildFilters($items),
            'format_filters' => $this->buildFormatFilters($items),
            'page'           => $page,
            'pages'          => $pages,
            'total'          => $total,
        ]);
    }

    /**
     * The view-model array `requests/_row.html.twig` renders — mirrors
     * RequestsController::buildItem().
     *
     * @return array<string, mixed>
     */
    private function buildItem(BookRequest $r, ?DownloadJob $job, BookMetadataService $metadata, \DateTimeImmutable $now): array
    {
        $staleBefore = $now->modify('-' . DownloadJobRepository::STALE_AFTER_SECONDS . ' seconds');
        // A job idle in an in-flight state past the stale window is orphaned
        // (worker died mid-download) — surface it so the row can say so.
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

    /**
     * The display status key for a request — mirrors
     * RequestsController::displayStatusKey(): 'available' → In Library,
     * approved + delivery complete → 'downloaded', else the stored status.
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
     * The status filter chips: "All" plus one per display status actually present,
     * in canonical order, each with its count — mirrors RequestsController::buildFilters().
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
     * The format filter chips: "All" plus Book / Audiobook with counts; hidden
     * entirely when only one format is present — mirrors
     * RequestsController::buildFormatFilters().
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

        if ($counts['book'] === 0 || $counts['audiobook'] === 0) {
            return [];
        }

        return [
            ['key' => 'all', 'label' => 'All formats', 'count' => \count($items)],
            ['key' => 'book', 'label' => 'Book', 'count' => $counts['book']],
            ['key' => 'audiobook', 'label' => 'Audiobook', 'count' => $counts['audiobook']],
        ];
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
