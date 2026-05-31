<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Download\FulfillmentLog;
use App\Message\DispatchReleaseSearch;
use App\Message\ProcessDownloadJob;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use App\Repository\DownloadJobRepository;
use App\Search\DirectDownload\DirectDownloadEvaluator;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * On approval: search the enabled release sources, score candidates, auto-pick
 * the best match, and create a queued DownloadJob — then hand off to
 * ProcessDownloadJob. If nothing qualifies, the request stays APPROVED (no job)
 * so an admin can retry later.
 */
#[AsMessageHandler]
final class DispatchReleaseSearchHandler
{
    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly DownloadJobRepository $jobs,
        private readonly DirectDownloadEvaluator $evaluator,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FulfillmentLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DispatchReleaseSearch $message): void
    {
        $request = $this->requests->find($message->bookRequestId);
        if ($request === null || $request->getStatus() !== BookRequest::STATUS_APPROVED) {
            return;
        }
        // Idempotency: don't spawn a second job if one is already in flight.
        if ($this->jobs->hasActiveJobForRequest($request)) {
            return;
        }

        $title = $request->getBook()->getTitle();
        $this->log->info('Searching for a release…', $title);

        $result = $this->evaluator->evaluate($this->planFor($request));

        if ($result->pick === null) {
            $reason = $result->unavailableReason ?? sprintf('no qualifying match among %d candidate(s)', $result->totalCount());
            $this->log->warn('No match — will retry later: ' . $reason, $title);
            $this->logger->info('Release search found no qualifying match', [
                'request' => $request->getId(),
                'reason'  => $result->unavailableReason,
                'scored'  => $result->totalCount(),
            ]);

            return;
        }

        $pick = $result->pick;
        $links = $this->linksForPick($result->scored, $pick);
        $this->log->info(sprintf(
            'Matched %s from %s (%d link(s)) — queued download',
            $pick->format ?? '?',
            $pick->indexer ?? $pick->source,
            \count($links),
        ), $title);

        $job = new DownloadJob(
            source: $pick->source,
            sourceId: $pick->sourceId,
            protocol: $pick->protocol ?? ReleaseCandidate::PROTOCOL_HTTP,
            bookRequest: $request,
        );
        $job->setFormat($pick->format);
        $job->setSizeBytes($pick->sizeBytes);
        $job->setCandidateLinks($links);
        $job->setDownloadUrl($links[0] ?? $pick->downloadUrl);
        $job->setStatus(DownloadJob::STATUS_QUEUED);

        $request->setDeliveryStatus(DownloadJob::STATUS_QUEUED);

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new ProcessDownloadJob((int) $job->getId()));
    }

    private function planFor(BookRequest $request): ReleaseSearchPlan
    {
        $book = $request->getBook();

        $isbns = [];
        foreach ([$book->getIsbn(), ...$book->getIsbns()] as $raw) {
            $normalized = BookRepository::normalizeIsbn($raw);
            if ($normalized !== null) {
                $isbns[$normalized] = true;
            }
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: array_keys($isbns),
            author: (string) $book->getAuthor(),
            titleVariants: [$book->getTitle()],
        );
    }

    /**
     * The concrete download URLs live on the pick's ScoredCandidate, not on the
     * bare ReleaseCandidate the selector returns — correlate by source+sourceId.
     *
     * @param list<\App\Search\DirectDownload\ScoredCandidate> $scored
     * @return list<string>
     */
    private function linksForPick(array $scored, ReleaseCandidate $pick): array
    {
        foreach ($scored as $entry) {
            if ($entry->candidate->source === $pick->source && $entry->candidate->sourceId === $pick->sourceId) {
                return $entry->detailLinks;
            }
        }

        return [];
    }
}
