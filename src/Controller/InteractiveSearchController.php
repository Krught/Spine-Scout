<?php

declare(strict_types=1);

namespace App\Controller;

use App\Download\Client\DownloadClientInterface;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Progress\CollectingDownloadProgressReporter;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\DirectDownload\ScoredCandidate;
use App\Search\Source\ReleaseCandidate;
use App\Service\BookMetadataService;
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
 * Open to any logged-in user (unlike the ROLE_ADMIN, non-prod-only dev probe).
 */
#[IsGranted('ROLE_USER')]
final class InteractiveSearchController extends AbstractController
{
    /** Server-side cap on returned rows — scoring fetches a detail page per row. */
    private const MAX_RESULTS = 25;

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
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The four sources with their operator-configured mirror URLs, so the panel
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

        // Operator priority order first (so the panel defaults to their highest
        // source), then any source not yet in their saved priority list.
        // HTTP mirror sources only — torrent isn't a manual, link-resolving source.
        $orderedIds = [];
        foreach ($config->indexerPriority as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id) && DirectDownloadSource::tryFromId($id)?->usesMirrors() && !in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }
        foreach (DirectDownloadSource::mirrorIds() as $id) {
            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        $sources = [];
        foreach ($orderedIds as $id) {
            $sources[] = [
                'id'      => $id,
                'label'   => DirectDownloadSource::tryFromId($id)?->label() ?? $id,
                'enabled' => $config->isIndexerEnabled($id),
                'mirrors' => $config->mirrorsFor($id)->toArray(),
            ];
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
            'results'   => $rows,
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
        $subject = $book->getTitle();

        $client = $this->httpClient();
        if ($client === null) {
            return $this->json(['error' => 'No HTTP download client is configured.'], 500);
        }

        $config = $this->probe->config();
        if (trim($config->outputDirectory) === '') {
            return $this->json(['error' => 'No output / watch folder configured in Settings → Direct downloads.'], 409);
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
        $this->recordDownload($book, $sourceId, $links, $finalPath, $format, $bytes);
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
    private function recordDownload(Book $book, string $sourceId, array $links, string $finalPath, ?string $format, ?int $bytes): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $request = $this->requests->findOneByUserAndBook($user, $book);
        if ($request === null) {
            $request = new BookRequest($user, $book);
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
        ];
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
