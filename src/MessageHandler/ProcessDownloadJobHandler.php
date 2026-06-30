<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Download\Client\DownloadClientInterface;
use App\Download\FileMover;
use App\Download\FilenameTemplate;
use App\Download\FulfillmentLog;
use App\Download\Metadata\EbookMetadataInjector;
use App\Download\Progress\FulfillmentDownloadProgressReporter;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Entity\Book;
use App\Entity\DownloadJob;
use App\Message\ProcessDownloadJob;
use App\Repository\BookRepository;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadCascade;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\DirectDownload\DownloadAttempt;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Drives one DownloadJob to completion by walking the direct-download cascade:
 * for each enabled source (priority order) it takes the top-3 qualifying items
 * and tries to download each against every one of that source's mirrors —
 * source 1 / mirror 1 / (item1,2,3), then mirror 2, … then source 2, etc. The
 * FIRST link that streams a file wins; the job is stamped with that source/item,
 * the file is moved into the output folder, and the request flips to delivered.
 *
 * The cascade is a lazy generator, so once a download succeeds, later sources are
 * never searched. On total failure the job is marked errored and the request
 * stays APPROVED — RetryApprovedSearches re-runs the whole cascade at the next
 * check (every 3h).
 */
#[AsMessageHandler]
final class ProcessDownloadJobHandler
{
    /**
     * @param iterable<DownloadClientInterface> $downloadClients
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('app.download_client')]
        private readonly iterable $downloadClients,
        private readonly DirectDownloadCascade $cascade,
        private readonly SearchSettingsProvider $settings,
        private readonly FileMover $mover,
        private readonly FilenameTemplate $filenames,
        private readonly EbookMetadataInjector $metadataInjector,
        private readonly TorrentFulfillmentInterface $torrents,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessDownloadJob $message): void
    {
        $job = $this->claim($message->downloadJobId);
        if ($job === null) {
            return;
        }

        $request = $job->getBookRequest();
        if ($request === null) {
            $this->fail($job, 'Download job has no associated request.');

            return;
        }

        $subject = $this->baseName($job);
        $plan = $this->planFor($request->getBook());

        // "Torrent" is one entry in the operator's Source priority. If it's the
        // highest-priority enabled source, try it before the HTTP cascade; otherwise
        // it runs as a fallback after the HTTP sources (below). A successful add hands
        // the job to the async torrent poller and we stop here.
        $ddConfig = $this->settings->getDirectDownloadConfig();
        $torrentEnabled = $ddConfig->isIndexerEnabled(DirectDownloadSource::Torrent->value) && $this->torrents->isAvailable();
        $torrentFirst = $torrentEnabled && $this->firstEnabledSource($ddConfig) === DirectDownloadSource::Torrent->value;
        if ($torrentFirst && $this->tryTorrent($job, $plan, $subject)) {
            return;
        }

        $staged = null;
        $picked = null;
        $lastError = 'no source/mirror/item produced a downloadable file';
        $attemptNo = 0;
        $policy = $this->settings->getBestMatchPolicy();

        foreach ($this->cascade->attempts($plan, $subject) as $attempt) {
            ++$attemptNo;
            $client = $this->clientFor($attempt->item->protocol ?? ReleaseCandidate::PROTOCOL_HTTP);
            if ($client === null) {
                $lastError = "No download client for protocol '" . ($attempt->item->protocol ?? '?') . "'.";
                continue;
            }

            $label = $this->attemptLabel($attempt, $attemptNo);
            $staged = $this->tryLinks($client, $attempt->links, $subject, $label, $lastError);
            if ($staged !== null) {
                // Final safety net: the file we just downloaded must be an allowed
                // format. Otherwise delete it (an invalid filetype must never land in
                // the library) and keep walking the cascade for a valid candidate.
                if (!$this->formatAllowed($attempt->item->format, $policy)) {
                    @unlink($staged);
                    $staged = null;
                    $lastError = sprintf(
                        "Downloaded file format '%s' is not in the format priority list.",
                        $attempt->item->format ?? '?',
                    );
                    $this->log->info($lastError . ' Deleted; trying next candidate.', $subject);
                    continue;
                }
                $picked = $attempt;
                break;
            }
        }

        if ($staged === null || $picked === null) {
            // HTTP cascade produced nothing. If torrent is enabled but wasn't already
            // tried first, try it now as a fallback before giving up.
            if ($torrentEnabled && !$torrentFirst && $this->tryTorrent($job, $plan, $subject)) {
                return;
            }
            $this->fail($job, 'All sources/mirrors/items failed: ' . $lastError);

            return;
        }

        // Stamp the job with the winning attempt before finalising.
        $job->setSource($picked->sourceId)
            ->setSourceId($picked->item->sourceId)
            ->setProtocol($picked->item->protocol ?? ReleaseCandidate::PROTOCOL_HTTP)
            ->setFormat($picked->item->format)
            ->setSizeBytes($picked->item->sizeBytes)
            ->setCandidateLinks($picked->links)
            ->setDownloadUrl($picked->links[0] ?? null);

        $config = $this->settings->getDirectDownloadConfig();
        if (trim($config->outputDirectory) === '') {
            @unlink($staged);
            $this->fail($job, 'No output / watch folder configured in Settings → Direct downloads.');

            return;
        }

        $filename = $this->filenames->render($config->filenameTemplate, $this->tokens($job), $job->getFormat());

        // Best-effort: rewrite the staged file's embedded metadata with our stored
        // values before it lands in the library. Never throws; skips when disabled
        // or for non-EPUB formats.
        if ($this->metadataInjector->inject($staged, $request->getBook(), $job->getFormat())) {
            $this->log->info('Rewrote embedded metadata from Spine Scout', $subject);
        }

        try {
            $finalPath = $this->mover->move($staged, $config->outputDirectory, $filename);
        } catch (\Throwable $e) {
            @unlink($staged);
            $this->fail($job, 'Move to output folder failed: ' . $e->getMessage());

            return;
        }

        $job->setFilePath($finalPath)
            ->setStatus(DownloadJob::STATUS_COMPLETE)
            ->setProgress(100)
            ->setStatusMessage(null);
        $this->mirrorDelivery($job);
        $this->em->flush();

        $this->log->info('Downloaded → ' . basename($finalPath) . ' (awaiting library import)', $subject);
        $this->logger->info('Download complete', ['job' => $job->getId(), 'path' => $finalPath, 'source' => $picked->sourceId]);
    }

    /**
     * Try the torrent source: search indexers, add the best match to the download
     * client, and hand the job to the async poller. Returns true when a torrent was
     * added (the caller stops here); false when nothing matched or the add failed.
     */
    private function tryTorrent(DownloadJob $job, ReleaseSearchPlan $plan, string $subject): bool
    {
        try {
            $added = $this->torrents->tryFulfill($job, $plan, $subject);
        } catch (\Throwable $e) {
            $this->log->warn('Torrent add failed: ' . $e->getMessage(), $subject);

            return false;
        }
        if (!$added) {
            return false;
        }
        $this->em->flush();
        $this->log->info('Handed to download client; awaiting completion', $subject);
        $this->logger->info('Book torrent queued', ['job' => $job->getId(), 'hash' => $job->getClientRef()]);

        return true;
    }

    /** Id of the highest-priority enabled source, or null when none are enabled. */
    private function firstEnabledSource(DirectDownloadConfig $config): ?string
    {
        foreach ($config->indexerPriority as $row) {
            if ($row['enabled'] ?? false) {
                return $row['id'];
            }
        }

        return null;
    }

    /**
     * Try each link of one attempt in order; return the staged file path on the
     * first that completes, or null. Updates $lastError for the caller's failure
     * message. Never throws.
     *
     * @param list<string> $links
     */
    private function tryLinks(DownloadClientInterface $client, array $links, string $subject, string $label, string &$lastError): ?string
    {
        foreach ($links as $i => $url) {
            $progress = new FulfillmentDownloadProgressReporter($this->log, $subject, sprintf('%s · link %d/%d', $label, $i + 1, \count($links)));
            try {
                $downloadId = $client->addDownload($url, $subject, ['progress' => $progress]);
                $status = $client->getStatus($downloadId);
                if ($status->isComplete() && $status->filePath !== null) {
                    return $status->filePath;
                }
                $lastError = $status->message ?? 'download did not complete';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->log->warn(sprintf('%s failed: %s', $label, $lastError), $subject);
                $this->logger->warning('Download attempt failed', ['url' => $url, 'error' => $lastError]);
            }
        }

        return null;
    }

    /** Human label for the activity monitor: "Anna's Archive · mirror.host · attempt N". */
    private function attemptLabel(DownloadAttempt $attempt, int $attemptNo): string
    {
        $source = DirectDownloadSource::tryFromId($attempt->sourceId)?->label() ?? $attempt->sourceId;
        $host = (string) (parse_url($attempt->mirror, PHP_URL_HOST) ?: $attempt->mirror);

        return sprintf('%s · %s · attempt %d', $source, $host, $attemptNo);
    }

    private function planFor(Book $book): ReleaseSearchPlan
    {
        // Build a deduped list<string> of ISBNs. NB: do NOT key an array by the
        // ISBN and array_keys() it — PHP coerces numeric-string keys to ints, and
        // ReleaseSearchPlan::isbnCandidates must stay strings (rawurlencode() etc.
        // reject ints).
        $isbns = [];
        $seen = [];
        foreach ([$book->getIsbn(), ...$book->getIsbns()] as $raw) {
            $normalized = BookRepository::normalizeIsbn($raw);
            if ($normalized !== null && !isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $isbns[] = $normalized;
            }
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: $isbns,
            author: (string) $book->getAuthor(),
            titleVariants: [$book->getTitle()],
        );
    }

    /**
     * Claim the job under a pessimistic write lock and flip it to downloading.
     * Returns null when the job is gone or already past the queued state (another
     * worker has it, or the message was redelivered).
     */
    private function claim(int $jobId): ?DownloadJob
    {
        return $this->em->wrapInTransaction(function () use ($jobId): ?DownloadJob {
            $job = $this->em->find(DownloadJob::class, $jobId, LockMode::PESSIMISTIC_WRITE);
            if ($job === null || $job->getStatus() !== DownloadJob::STATUS_QUEUED) {
                return null;
            }
            $job->setStatus(DownloadJob::STATUS_DOWNLOADING)->setProgress(0);
            $this->mirrorDelivery($job);

            return $job;
        });
    }

    private function clientFor(string $protocol): ?DownloadClientInterface
    {
        foreach ($this->downloadClients as $client) {
            if ($client->getProtocol() === $protocol && $client->isConfigured()) {
                return $client;
            }
        }

        return null;
    }

    private function fail(DownloadJob $job, string $message): void
    {
        $job->setStatus(DownloadJob::STATUS_ERROR)->setStatusMessage($message);
        $this->mirrorDelivery($job);
        $this->em->flush();
        $this->log->error('Download failed: ' . $message, $this->baseName($job));
        $this->logger->warning('Download job failed', ['job' => $job->getId(), 'error' => $message]);
    }

    private function mirrorDelivery(DownloadJob $job): void
    {
        $job->getBookRequest()?->setDeliveryStatus($job->getStatus());
    }

    /**
     * @return array<string, string|null>
     */
    private function tokens(DownloadJob $job): array
    {
        $book = $job->getBookRequest()?->getBook();
        $year = null;
        if ($book !== null && $book->getPublishedDate() !== null && preg_match('/(\d{4})/', $book->getPublishedDate(), $m)) {
            $year = $m[1];
        }
        $isbn = $book?->getIsbn() ?? ($book?->getIsbns()[0] ?? null);

        return [
            'author' => $book?->getAuthor(),
            'title'  => $book?->getTitle() ?? $job->getSourceId(),
            'year'   => $year,
            'isbn'   => $isbn,
            'format' => $job->getFormat(),
        ];
    }

    private function baseName(DownloadJob $job): string
    {
        return $job->getBookRequest()?->getBook()->getTitle() ?? $job->getSourceId();
    }

    /**
     * A downloaded file's format must be in the policy's format-priority allow-list.
     * An empty list means "no preference / allow all", matching BestMatchSelector.
     */
    private function formatAllowed(?string $format, BestMatchPolicy $policy): bool
    {
        if ($policy->formatPriority === []) {
            return true;
        }

        return $format !== null
            && in_array(strtolower($format), array_map('strtolower', $policy->formatPriority), true);
    }
}
