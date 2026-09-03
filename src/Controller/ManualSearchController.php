<?php

declare(strict_types=1);

namespace App\Controller;

use App\Download\Client\DownloadClientInterface;
use App\Download\FileMover;
use App\Download\Mam\MamFulfillment;
use App\Download\Progress\CollectingDownloadProgressReporter;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Entity\DownloadJob;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Interactive\InteractiveSearchRunner;
use App\Search\Mam\MamCandidateMapper;
use App\Search\Manual\ReleaseNameParser;
use App\Search\Source\ReleaseCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Manual Search" — interactive search with the book taken away.
 *
 * Interactive search always hangs off a resolved Book: the panel opens from a
 * book modal, the grab creates/updates that book's request, and the filename is
 * rendered from the book's metadata. That makes it useless for anything the
 * metadata provider doesn't know — an obscure release, a bundle, a foreign
 * edition. This page is the same search over the same sources with no book at
 * all: free-text query, an ebook/audiobook toggle that picks the destination,
 * and an operator-approved filename typed into the panel before the grab.
 *
 * The search half is literally the same code (InteractiveSearchRunner), so the
 * two pages can never disagree about which sources exist, in what order, or what
 * a result row looks like. Only two things are added on top:
 *
 *  - `suggestedName` per row — ReleaseNameParser's best-effort read of the
 *    release name, pre-filled into the panel's editable name field.
 *  - manual delivery — the job carries the approved name and the medium instead
 *    of a BookRequest (see DownloadJob::$manualName / TorrentFinalizer).
 *
 * Gated on its own ROLE_MANUAL_SEARCH: this route downloads arbitrary files into
 * the library under an arbitrary name, which is a strictly bigger permission
 * than searching for a book the instance already tracks.
 */
#[IsGranted('ROLE_MANUAL_SEARCH')]
final class ManualSearchController extends AbstractController
{
    private const CSRF_ID = 'manual_search';

    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly InteractiveSearchRunner $runner,
        private readonly ReleaseNameParser $names,
        private readonly DirectDownloadProbe $probe,
        private readonly IntegrationRepository $integrations,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly FileMover $mover,
        private readonly TorrentFulfillmentInterface $torrents,
        private readonly MamFulfillment $mamFulfillment,
        private readonly MyAnonamouseSettingsProvider $mamSettings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/manual-search', name: 'manual_search', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manual_search/index.html.twig');
    }

    /**
     * The same source list interactive search offers, in the same operator
     * priority order — Manual Search searches every enabled source, so it must not
     * hide or reorder any of them.
     */
    #[Route('/manual-search/sources', name: 'manual_search_sources', methods: ['POST'])]
    public function sources(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }

        return $this->json(['sources' => $this->runner->sources()]);
    }

    /**
     * Run one source with the free-text query. Identical to interactive search's
     * run(), except that with no book behind it the ebook/audiobook split can only
     * come from the payload's toggle, and every row is given the `suggestedName`
     * the panel pre-fills into its editable name field.
     */
    #[Route('/manual-search/run', name: 'manual_search_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);
        $audiobook = self::audiobook($payload);

        $outcome = $this->runner->run($payload, $audiobook);
        $body = $outcome->body;
        if (is_array($body['results'] ?? null)) {
            $template = $this->template($audiobook);
            $body['results'] = array_map(
                function (array $row) use ($template, $audiobook): array {
                    // An audiobook release becomes a FOLDER, so it gets no extension;
                    // an ebook keeps the row's own format as the file extension.
                    $extension = $audiobook ? null : self::blankToNull($row['format'] ?? null);
                    $row['suggestedName'] = $this->names->suggest((string) ($row['title'] ?? ''), $template, $extension);

                    return $row;
                },
                $body['results'],
            );
        }

        return $this->json($body, $outcome->status);
    }

    /**
     * Send one picked torrent / MAM release to the download client with no book
     * request behind it: the job carries the operator's approved name and their
     * ebook/audiobook choice, and TorrentFinalizer delivers on those when the
     * torrent completes.
     */
    #[Route('/manual-search/grab', name: 'manual_search_grab', methods: ['POST'])]
    public function grab(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);

        $isMam = trim((string) ($payload['source'] ?? '')) === DirectDownloadSource::Mam->value;
        $title = (string) (self::blankToNull($payload['title'] ?? null) ?? '');

        // MAM rides its own fulfillment (the .torrent bytes come off the row's dl
        // hash, optionally spending a wedge); everything else is a plain magnet.
        $mam = is_array($payload['mam'] ?? null) ? $payload['mam'] : [];
        $torrentId = is_numeric($mam['torrentId'] ?? null) ? (int) $mam['torrentId'] : 0;
        $dlHash = is_string($mam['dlHash'] ?? null) ? trim($mam['dlHash']) : '';
        $link = (string) (self::blankToNull($payload['link'] ?? null) ?? '');

        if ($isMam) {
            if ($title === '' || $torrentId <= 0 || $dlHash === '') {
                return $this->json(['error' => 'This result is missing its MyAnonamouse download data.'], 400);
            }
            if (!$this->mamFulfillment->isAvailable()) {
                return $this->json(['error' => 'MyAnonamouse downloading is not configured.'], 409);
            }
        } else {
            if ($link === '' || $title === '') {
                return $this->json(['error' => 'This result has no download link.'], 400);
            }
            if (!$this->torrents->isAvailable()) {
                return $this->json(['error' => 'Torrent downloading is not configured.'], 409);
            }
        }

        $audiobook = self::audiobook($payload);
        // No extension here: a torrent's real extension is only known once the
        // completed files are on disk, so TorrentFinalizer appends it (ebooks) or
        // uses the bare name as the album folder (audiobooks).
        $name = $this->deliveryName($payload, $title, $audiobook, null);

        $sourceId = $isMam
            ? (string) $torrentId
            : (string) (self::blankToNull($payload['id'] ?? null) ?? $link);
        $job = new DownloadJob(
            source: $isMam ? DirectDownloadSource::Mam->value : DirectDownloadSource::Torrent->value,
            sourceId: mb_substr($sourceId, 0, 255),
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            bookRequest: null,
        );
        $job->setStatus(DownloadJob::STATUS_QUEUED)
            ->setManualName($name)
            ->setManualAudiobook($audiobook);
        $this->em->persist($job);

        $useWedge = false;
        if ($isMam) {
            $config = $this->mamSettings->getMyAnonamouseConfig();
            $free = (bool) filter_var($mam['free'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $flVip = (bool) filter_var($mam['flVip'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $personal = (bool) filter_var($mam['personalFreeleech'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $userIsVip = (bool) ($this->mamSettings->getMamAccountState()['isVip'] ?? false);
            $alreadyFree = $free || $personal || ($userIsVip && $flVip);

            // Exactly the re-validation InteractiveSearchController::grabMam() does:
            // the panel's checkbox is only a request, so a stale or forged payload
            // can neither waste a wedge on an already-free release nor dodge the
            // operator's "always use wedge" setting.
            $useWedge = (bool) filter_var($payload['useWedge'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($alreadyFree) {
                $useWedge = false;
            } elseif ($config->alwaysUseWedge) {
                $useWedge = true;
            }

            if ($link === '') {
                $link = rtrim($config->baseUrl, '/') . '/tor/download.php/' . $dlHash;
            }
            $candidate = $this->candidate($payload, $title, $link, $sourceId, $audiobook, [
                'mam' => [
                    'torrentId'         => $torrentId,
                    'dlHash'            => $dlHash,
                    'free'              => $free,
                    'flVip'             => $flVip,
                    'personalFreeleech' => $personal,
                ],
            ], MamCandidateMapper::INDEXER, DirectDownloadSource::Mam->value);
        } else {
            $candidate = $this->candidate($payload, $title, $link, $sourceId, $audiobook, [], null, 'prowlarr');
        }

        // Soft failure at 200, matching interactive search: the panel renders the
        // message inline instead of treating it as a transport error.
        try {
            $added = $isMam
                ? $this->mamFulfillment->grab($job, $candidate, $name, $useWedge)
                : $this->torrents->grab($job, $candidate, $name);
            $error = $added ? null : 'No torrent download client is configured.';
        } catch (\Throwable $e) {
            $error = 'Download client add failed: ' . $e->getMessage();
        }

        if ($error !== null) {
            $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($error);
            $this->em->flush();
            $this->logger->warning('Manual grab failed', ['name' => $name, 'error' => $error]);

            return $this->json(['ok' => false, 'queued' => false, 'jobId' => null, 'error' => $error]);
        }

        $this->em->flush();
        $this->logger->info('Manual grab queued', [
            'name' => $name, 'job' => $job->getId(), 'hash' => $job->getClientRef(), 'wedge' => $useWedge,
        ]);

        return $this->json([
            'ok'     => true,
            'queued' => true,
            'jobId'  => $job->getId(),
            'error'  => null,
        ]);
    }

    /**
     * The HTTP direct-download path: stage the file through the download client,
     * then move it into the destination the medium toggle picked, under the
     * operator's approved name. No metadata injection — there is no book whose
     * metadata could be written into the file, and guessing one would corrupt a
     * perfectly good release.
     */
    #[Route('/manual-search/download', name: 'manual_search_download', methods: ['POST'])]
    public function download(Request $request): JsonResponse
    {
        if (($error = $this->guardCsrf($request)) !== null) {
            return $error;
        }
        $payload = $this->payload($request);

        $links = array_values(array_filter(
            (array) ($payload['links'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        if ($links === []) {
            return $this->json(['error' => 'This result has no download links.'], 400);
        }

        $sourceId = trim((string) ($payload['source'] ?? ''));
        $format = self::blankToNull($payload['format'] ?? null);
        $audiobook = self::audiobook($payload);
        $title = (string) (self::blankToNull($payload['title'] ?? null) ?? 'download');
        $filename = $this->deliveryName($payload, $title, $audiobook, $format);

        $client = $this->httpClient();
        if ($client === null) {
            return $this->json(['error' => 'No HTTP download client is configured.'], 500);
        }

        $destination = $this->destination($audiobook);
        if ($destination === null) {
            return $this->json([
                'error' => $audiobook
                    ? 'No audiobook destination directory configured in Settings → Torrents.'
                    : 'No ebook library / watch folder configured in Settings → Ebooks.',
            ], 409);
        }

        // Try each link in order; first one that stages a file wins (failover).
        $progress = new CollectingDownloadProgressReporter();
        $staged = null;
        $lastError = 'no link produced a downloadable file';
        foreach ($links as $url) {
            try {
                $id = $client->addDownload($url, $filename, ['progress' => $progress]);
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

        try {
            $finalPath = $this->mover->move($staged, $destination, $filename);
        } catch (\Throwable $e) {
            @unlink($staged);

            return $this->json([
                'ok'    => false,
                'error' => 'Move to output folder failed: ' . $e->getMessage(),
                'steps' => $progress->entries(),
            ]);
        }

        $bytes = @filesize($finalPath) ?: null;
        $this->recordDownload($sourceId, $links, $finalPath, $format, $bytes, $filename, $audiobook);
        $progress->step('Downloaded → ' . basename($finalPath) . ' (awaiting library import)');
        $this->logger->info('Manual download complete', ['path' => $finalPath, 'source' => $sourceId]);

        return $this->json([
            'ok'       => true,
            'filename' => basename($finalPath),
            'bytes'    => $bytes,
            'steps'    => $progress->entries(),
            'error'    => null,
        ]);
    }

    /**
     * The name the file (or album folder) will actually be written under: the
     * operator's edited `saveAs`, sanitized so it can never carry a path separator
     * or a `..` out of the destination directory, falling back to the release
     * title when they cleared the field.
     *
     * @param array<string, mixed> $payload
     */
    private function deliveryName(array $payload, string $releaseTitle, bool $audiobook, ?string $extension): string
    {
        $saveAs = self::blankToNull($payload['saveAs'] ?? null);
        // "../.." and friends carry no name at all: sanitize() would answer
        // "download", but the release's own name is a far better answer.
        $hasName = $saveAs !== null && trim((string) preg_replace('#[\\\\/.\s]+#', '', $saveAs)) !== '';
        if ($saveAs !== null && $hasName) {
            return ReleaseNameParser::sanitize($saveAs, $extension);
        }

        // Blank (or nothing but separators): re-derive the suggestion the panel showed.
        return $this->names->suggest($releaseTitle, $this->template($audiobook), $extension);
    }

    /** The operator's filename template for the medium being delivered. */
    private function template(bool $audiobook): string
    {
        return $audiobook
            ? $this->integrations->getTorrentClientConfig()->filenameTemplate
            : $this->probe->config()->filenameTemplate;
    }

    /**
     * Where a finished manual download lands. Mirrors TorrentFinalizer exactly:
     * ebooks go to the ebook library/watch folder, audiobooks to the audio output
     * directory unless the operator pointed audiobooks at the ebook library too.
     * Null when the relevant directory was never configured.
     */
    private function destination(bool $audiobook): ?string
    {
        $ebookDir = trim($this->probe->config()->outputDirectory);
        if (!$audiobook) {
            return $ebookDir !== '' ? $ebookDir : null;
        }

        $config = $this->integrations->getTorrentClientConfig();
        $dir = $config->useEbookLibraryDir ? $ebookDir : trim($config->audioOutputDirectory);

        return $dir !== '' ? $dir : null;
    }

    /**
     * The picked release as a candidate for the fulfillment services. Every field
     * is whatever the row carried — there is no book to validate it against.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $extra
     */
    private function candidate(
        array $payload,
        string $title,
        string $link,
        string $sourceId,
        bool $audiobook,
        array $extra,
        ?string $forcedIndexer,
        string $source,
    ): ReleaseCandidate {
        return new ReleaseCandidate(
            source: $source,
            sourceId: $sourceId,
            title: $title,
            format: self::blankToNull($payload['format'] ?? null),
            sizeBytes: isset($payload['sizeBytes']) && is_numeric($payload['sizeBytes']) ? (int) $payload['sizeBytes'] : null,
            downloadUrl: $link,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: $forcedIndexer ?? self::blankToNull($payload['indexer'] ?? null),
            seeders: isset($payload['seeders']) && is_numeric($payload['seeders']) ? (int) $payload['seeders'] : null,
            contentType: $audiobook ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK,
            extra: $extra,
        );
    }

    /**
     * Persist the finished download as a completed, book-less DownloadJob — the
     * same record the automatic pipeline writes, minus the BookRequest, so the
     * Activity/history views still show what was fetched and under what name.
     *
     * @param list<string> $links
     */
    private function recordDownload(string $sourceId, array $links, string $finalPath, ?string $format, ?int $bytes, string $name, bool $audiobook): void
    {
        $job = new DownloadJob(
            $sourceId !== '' ? $sourceId : 'manual',
            mb_substr($name, 0, 255),
            ReleaseCandidate::PROTOCOL_HTTP,
            null,
        );
        $job->setFormat($format)
            ->setSizeBytes($bytes)
            ->setCandidateLinks($links)
            ->setDownloadUrl($links[0] ?? null)
            ->setFilePath($finalPath)
            ->setManualName($name)
            ->setManualAudiobook($audiobook)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100);

        $this->em->persist($job);
        $this->em->flush();
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

    /**
     * Manual search has no book to fall back on, so the payload's toggle is the
     * only signal — anything unparseable means ebook, the safer default (it lands
     * in the watch folder rather than the audiobook library).
     *
     * @param array<string, mixed> $payload
     */
    private static function audiobook(array $payload): bool
    {
        return (bool) filter_var($payload['audiobook'] ?? false, FILTER_VALIDATE_BOOLEAN);
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

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
