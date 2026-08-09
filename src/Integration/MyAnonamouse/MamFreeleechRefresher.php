<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

use App\Entity\Book;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Integration\Hardcover\Dto\TrendingBook;
use App\Integration\Hardcover\HardcoverClient;
use App\Integration\Hardcover\HardcoverException;
use App\Repository\BookRepository;
use App\Repository\FreeleechItemRepository;
use App\Repository\IntegrationRepository;
use App\Search\Match\MatchScorer;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One freeleech sweep: pull what MAM says is free right now, mirror it into
 * `freeleech_items`, drop what rotated out, and reverse-look-up the new rows against
 * Hardcover so a resolved item becomes an ordinary catalog book carrying a freeleech
 * flag. Shared by the scheduled handler and `spinescout:mam:probe` (the
 * TorrentJobReconciler convention), so both take exactly the same path.
 *
 * Never throws: every failure degrades into the returned summary and the integration
 * row's lastError.
 */
final class MamFreeleechRefresher
{
    /** MouseSearch's Hardcover resolver ships the same confidence floor. */
    private const MATCH_THRESHOLD = 78;

    /** Used when the operator has not set the row's cadence yet (the column default is not it). */
    private const DEFAULT_INTERVAL_MINUTES = 360;

    /** MouseSearch re-registers the seedbox IP on the same cadence. */
    private const IP_UPDATE_INTERVAL_SECONDS = 10800;

    private const PENDING_BATCH = 200;
    private const HARDCOVER_SEARCH_LIMIT = 10;

    /**
     * What one scheduled resolution tick reverse-looks-up: the whole pending batch. The
     * handler chains a follow-up run whenever a tick leaves work behind and made progress,
     * so throughput is governed by Hardcover's 429 (early stop + backoff), not by this cap.
     */
    public const RESOLVE_CRON_BATCH = self::PENDING_BATCH;

    /** How long a 429 parks the resolution sweep; the ticks inside the window cost zero HTTP. */
    private const RESOLVE_BACKOFF_MINUTES = 15;

    /** MouseSearch paces the same resolver at ~60 requests/minute; anything faster earns a 429. */
    private const HARDCOVER_LOOKUP_DELAY_US = 1_000_000;

    public function __construct(
        private readonly MyAnonamouseClient $client,
        private readonly MyAnonamouseSettingsProvider $settings,
        private readonly IntegrationRepository $integrations,
        private readonly FreeleechItemRepository $items,
        private readonly BookRepository $books,
        private readonly HardcoverClient $hardcover,
        private readonly MatchScorer $scorer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $maxResolutions caps how many pending items this run reverse-looks-up (each lookup
     * costs a paced second), so a synchronous caller such as the settings "Refresh now"
     * button can return promptly; null keeps the full PENDING_BATCH the scheduled run takes.
     *
     * @return array{skipped: bool, fetched: int, vipFetched: int, new: int, deleted: int, resolved: int, localResolved: int, unmatched: int, pendingLeft: int, error: ?string}
     */
    public function refresh(bool $force = false, ?int $maxResolutions = null): array
    {
        $config = $this->settings->getMyAnonamouseConfig();
        if (!$config->enabled || !$this->client->isConfigured()) {
            return self::summary(skipped: true);
        }

        $integration = $this->integrations->getOrCreate(Integration::KIND_MYANONAMOUSE);
        // getOrCreate() hands back an unmanaged row; persisting it now (rather than at the
        // closing flush) keeps saveMamAccountState()'s own getOrCreate from creating a second
        // one and tripping the unique kind constraint.
        if ($integration->getId() === null) {
            $integration->setAuthType(Integration::AUTH_API_KEY);
            $this->em->persist($integration);
            $this->em->flush();
            $this->integrations->clearSettingsCache();
        }
        if (!$force && !$this->isDue($integration)) {
            return self::summary(skipped: true);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $errors = [];

        $categories = $config->enabledMainCategories();
        $seen = [];
        $fetched = 0;
        $vipFetched = 0;
        $new = 0;

        // The regular freeleech set is the primary one, so every category's phase A runs
        // before any VIP call: if MAM cuts the session off mid-sweep, the cut lands on the
        // superset pass, never on the set the shelf is actually built from.
        $phaseAIds = [];
        $regularEmpty = [];
        foreach ($categories as $mainCat) {
            $releases = $this->client->fetchFreeleech($mainCat, MyAnonamouseClient::SEARCH_TYPE_FREELEECH);
            $fetched += \count($releases);
            $regularEmpty[$mainCat] = $releases === [];
            foreach ($releases as $release) {
                $phaseAIds[$release->mamTorrentId] = true;
            }
            $new += $this->upsertReleases($releases, $config, $now, $seen);
        }

        // 'fl-VIP' is freeleech OR VIP, so it repeats everything phase A already returned;
        // only the ids phase A did not carry are VIP-only additions. The whole phase is opt-in:
        // with fetchVipFreeleech off not one MAM request is spent on it, and the VIP-only rows
        // an earlier ON sweep left behind are cleaned out below instead.
        $vipSkipped = false;
        $vipIncomplete = false;
        if ($config->fetchVipFreeleech) {
            foreach ($categories as $mainCat) {
                if ($regularEmpty[$mainCat]) {
                    $vipSkipped = true;
                    $vipIncomplete = true;
                    continue;
                }
                $releases = $this->client->fetchFreeleech(
                    $mainCat,
                    MyAnonamouseClient::SEARCH_TYPE_FREELEECH_OR_VIP,
                    maxItems: $config->vipFetchLimit,
                );
                if ($releases === []) {
                    $vipIncomplete = true;
                    continue;
                }
                $additions = array_values(array_filter(
                    $releases,
                    static fn (MamRelease $release): bool => !isset($phaseAIds[$release->mamTorrentId]),
                ));
                $vipFetched += \count($additions);
                $new += $this->upsertReleases($additions, $config, $now, $seen);
            }
        }

        // A category that answers with nothing is ambiguous — genuinely empty, or a dead
        // cookie the transport already swallowed. Wiping the table on the second reading
        // would blank the shelf for six hours, so an all-empty sweep only sweeps once
        // jsonLoad.php proves the session is still alive.
        $userInfo = null;
        $userInfoFetched = false;
        $mayDelete = $fetched > 0;
        if (!$mayDelete && $categories !== []) {
            $userInfo = $this->client->fetchUserInfo();
            $userInfoFetched = true;
            $mayDelete = $userInfo !== null;
            if (!$mayDelete) {
                $errors[] = 'MyAnonamouse returned no freeleech items and no account JSON — kept the existing rows.';
            }
        }
        if ($categories === []) {
            $errors[] = 'No formats are enabled for MyAnonamouse — nothing was fetched.';
        }
        // A sweep that found nothing and proved the session alive did complete — MAM simply has
        // nothing free — so the skipped VIP pass is not worth reporting there.
        if ($vipSkipped && ($fetched > 0 || !$mayDelete)) {
            $errors[] = 'VIP freeleech skipped — the regular sweep did not complete.';
        }

        // findPending() is a query, so the rows created above have to reach the database
        // before the resolution sweep can see them; the bookkeeping below rides the final flush.
        $this->em->flush();

        // A VIP phase that never ran, or answered with the same ambiguous emptiness the regular
        // sweep is already guarded against, leaves the VIP rows unconfirmed. Deleting them would
        // wipe a still-valid VIP shelf on a mid-run cutoff, so every existing fl_vip row joins
        // the keep-list for this sweep and gets re-checked once a VIP phase completes.
        $keep = array_map('intval', array_keys($seen));
        if ($config->fetchVipFreeleech && $vipIncomplete) {
            foreach ($this->items->findBy(['flVip' => true]) as $item) {
                $keep[] = $item->getMamTorrentId();
            }
            $keep = array_values(array_unique($keep));
        }

        // With the VIP pull off, nothing this sweep saw can confirm a VIP-only row, so the rows a
        // previously-on sweep left behind are dropped outright — they are not free for this
        // account and must not linger in the shelf. This is a config decision, not a reading of
        // MAM's answer, so it stands even when the ambiguous-empty guard forbids the sweep delete.
        $deleted = $config->fetchVipFreeleech ? 0 : $this->items->deleteVipOnly();
        $deleted += $mayDelete ? $this->items->deleteWhereMamTorrentIdNotIn($keep) : 0;

        $resolution = $this->resolvePending($maxResolutions);
        if ($resolution['error'] !== null) {
            $errors[] = $resolution['error'];
        }

        if (!$userInfoFetched) {
            $userInfo = $this->client->fetchUserInfo();
        }
        $state = $this->settings->getMamAccountState();
        if ($userInfo === null) {
            $errors[] = 'MyAnonamouse account check failed — the session cookie may be expired.';
        } else {
            $state['username']  = $userInfo['username'];
            $state['class']     = $userInfo['class'];
            $state['ratio']     = $userInfo['ratio'];
            $state['isVip']     = $userInfo['isVip'];
            $state['checkedAt'] = $now->format(\DateTimeInterface::ATOM);
        }
        if ($config->dynamicSeedboxUpdate && $this->ipUpdateDue($state, $now)) {
            $state['lastIpUpdateOk'] = $this->client->updateDynamicSeedboxIp();
            $state['lastIpUpdateAt'] = $now->format(\DateTimeInterface::ATOM);
        }
        $this->settings->saveMamAccountState($state);

        $error = $errors === [] ? null : implode(' ', $errors);
        $integration->setLastSyncAt($now);
        $integration->setLastError($error);
        $integration->touch();
        $this->em->flush();

        return self::summary(
            fetched: $fetched,
            vipFetched: $vipFetched,
            new: $new,
            deleted: $deleted,
            resolved: $resolution['resolved'],
            localResolved: $resolution['localResolved'],
            unmatched: $resolution['unmatched'],
            pendingLeft: $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING],
            error: $error,
        );
    }

    /**
     * The pending → resolved half of the sweep on its own: a free local-catalog pass over every
     * pending row, then a batched Hardcover reverse lookup for whatever the catalog could not
     * match. Owns its flush, needs no MAM session (only the local database and Hardcover), and
     * runs both from refresh() and from its own five-minute schedule.
     *
     * $maxResolutions caps the Hardcover half; null keeps the full PENDING_BATCH.
     *
     * @return array{skipped: bool, resolved: int, localResolved: int, unmatched: int, deferred: int, pendingLeft: int, error: ?string}
     */
    public function resolvePending(?int $maxResolutions = null): array
    {
        if (!$this->settings->getMyAnonamouseConfig()->enabled) {
            return self::resolution(skipped: true);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (self::backoffActive($this->settings->getMamAccountState(), $now)) {
            return self::resolution(skipped: true);
        }

        $pending = $this->items->findPending(self::PENDING_BATCH);
        if ($pending === []) {
            return self::resolution(skipped: true);
        }

        $errors = [];
        $resolved = 0;
        $localResolved = 0;
        $unmatched = 0;
        $deferred = null;

        $catalog = $this->books->titleAuthorKeyMap();
        // Two pending rows routinely reverse-look-up to the same Hardcover slug — the ebook and
        // the audiobook edition of one title are two MAM torrents but one catalog book. The
        // upsert's existence check is a query, so it cannot see a Book this run persisted but
        // has not flushed; without this registry the second row inserts a duplicate and the
        // closing flush dies on books_source_external_uniq, taking the whole sweep with it.
        $resolvedBooks = [];
        $remaining = [];
        foreach ($pending as $item) {
            $book = $catalog === [] ? null : $this->localMatch($item, $catalog);
            if ($book === null) {
                $remaining[] = $item;
                continue;
            }
            $item->setBook($book);
            $item->setResolution(FreeleechItem::RESOLUTION_RESOLVED);
            // 'resolved' stays the run's whole resolved count (it is what the settings
            // message renders); 'localResolved' only breaks out the free half of it.
            ++$localResolved;
            ++$resolved;
        }

        if ($remaining !== []) {
            $hardcover = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
            if ($hardcover === null || !$hardcover->isEnabled() || !$hardcover->hasCredentials()) {
                $errors[] = sprintf('Hardcover is not configured — %d item(s) left pending.', \count($remaining));
            } else {
                $budget = $maxResolutions ?? \count($remaining);
                $targets = \array_slice($remaining, 0, max(0, $budget));
                $processed = 0;
                $requests = 0;
                foreach (array_chunk($targets, HardcoverClient::SEARCH_BATCH_SIZE) as $chunk) {
                    $queries = [];
                    foreach ($chunk as $offset => $item) {
                        $queries[$offset] = self::lookupQuery($item);
                    }

                    $results = null;
                    if (array_filter($queries, static fn (string $q): bool => $q !== '') !== []) {
                        if ($requests > 0) {
                            usleep(self::HARDCOVER_LOOKUP_DELAY_US);
                        }
                        ++$requests;
                        try {
                            $results = $this->hardcover->searchBooksBatch($hardcover, $queries, self::HARDCOVER_SEARCH_LIMIT);
                        } catch (\Throwable $e) {
                            $this->logger->warning('MyAnonamouse freeleech batched reverse lookup failed', [
                                'items' => \count($chunk),
                                'error' => $e->getMessage(),
                            ]);
                            // Once Hardcover starts refusing, every remaining item would refuse too;
                            // stopping leaves them pending for the next run instead of burning them.
                            if (self::isRateLimited($e)) {
                                $deferred = \count($remaining) - $processed;
                                break;
                            }
                            // Anything else (an API that will not take aliased queries, say) drops
                            // this chunk back onto the one-request-per-item path it used to take,
                            // so a batched run can never resolve less than an unbatched one.
                            $results = null;
                        }
                    }

                    $rateLimited = false;
                    foreach ($chunk as $offset => $item) {
                        $query = $queries[$offset];
                        $candidates = $results[$offset] ?? null;
                        if ($candidates === null && $query !== '') {
                            if ($requests > 0) {
                                usleep(self::HARDCOVER_LOOKUP_DELAY_US);
                            }
                            ++$requests;
                            try {
                                $candidates = $this->hardcover->searchBooks($hardcover, $query, self::HARDCOVER_SEARCH_LIMIT);
                            } catch (\Throwable $e) {
                                $this->logger->warning('MyAnonamouse freeleech reverse lookup failed', [
                                    'mamTorrentId' => $item->getMamTorrentId(),
                                    'error'        => $e->getMessage(),
                                ]);
                                if (self::isRateLimited($e)) {
                                    $deferred = \count($remaining) - $processed;
                                    $rateLimited = true;
                                    break;
                                }
                                continue;
                            }
                        }
                        ++$processed;
                        if ($this->apply($item, $candidates ?? [], $now, $resolvedBooks)) {
                            ++$resolved;
                        } else {
                            ++$unmatched;
                        }
                    }
                    if ($rateLimited) {
                        break;
                    }
                }
                if ($deferred !== null) {
                    $errors[] = sprintf(
                        'Hardcover rate limit hit — %d item(s) deferred to the next sweep.',
                        $deferred,
                    );
                }
            }
        }

        $this->em->flush();
        if ($deferred !== null) {
            $this->parkUntilRateLimitClears($now);
        }

        return self::resolution(
            resolved: $resolved,
            localResolved: $localResolved,
            unmatched: $unmatched,
            deferred: $deferred ?? 0,
            pendingLeft: $this->items->countByResolution()[FreeleechItem::RESOLUTION_PENDING],
            error: $errors === [] ? null : implode(' ', $errors),
        );
    }

    /**
     * Mirrors one phase's releases into `freeleech_items` and stamps each id into $seen,
     * which the delete guard reads as the union of both phases.
     *
     * @param list<MamRelease> $releases
     * @param array<int, true> $seen
     *
     * @return int rows created
     */
    private function upsertReleases(array $releases, MyAnonamouseConfig $config, \DateTimeImmutable $now, array &$seen): int
    {
        $kept = [];
        foreach ($releases as $release) {
            if ($release->mamTorrentId <= 0 || $release->seeders < $config->minSeeders) {
                continue;
            }
            $kept[$release->mamTorrentId] = $release;
        }
        if ($kept === []) {
            return 0;
        }

        $new = 0;
        $existing = $this->items->findByMamTorrentIds(array_keys($kept));
        foreach ($kept as $mamTorrentId => $release) {
            $item = $existing[$mamTorrentId] ?? null;
            if ($item === null) {
                $item = new FreeleechItem($mamTorrentId, $release->title, $release->audiobook);
                $item->setResolution(FreeleechItem::RESOLUTION_PENDING);
                $this->em->persist($item);
                ++$new;
            }
            $this->applyRelease($item, $release, $now);
            $seen[$mamTorrentId] = true;
        }

        return $new;
    }

    private function applyRelease(FreeleechItem $item, MamRelease $release, \DateTimeImmutable $now): void
    {
        $item
            ->setTitle($release->title)
            ->setAuthors($release->authors)
            ->setNarrators($release->narrators)
            ->setAudiobook($release->audiobook)
            ->setCatName($release->catName)
            ->setLangCode($release->langCode)
            ->setFiletypes($release->filetypes)
            ->setSizeBytes($release->sizeBytes)
            ->setSeeders($release->seeders)
            ->setLeechers($release->leechers)
            ->setTimesCompleted($release->timesCompleted)
            ->setVip($release->vip)
            ->setFlVip($release->flVip)
            ->setFree($release->free)
            ->setPersonalFreeleech($release->personalFreeleech)
            ->setDlHash($release->dlHash)
            ->setThumbnailUrl($release->thumbnailUrl)
            ->setAddedAt($release->addedAt)
            ->setLastSeenAt($now);
    }

    /**
     * The pending item as an already-catalogued book, matched on the same normalized
     * title|author key the downloaded-keys index uses. Nothing here costs an HTTP request.
     *
     * @param array<string, int> $catalog
     */
    private function localMatch(FreeleechItem $item, array $catalog): ?Book
    {
        $key = BookRepository::normalizeTitleAuthor(self::cleanTitle($item->getTitle()), $item->getAuthors()[0] ?? null);
        if ($key === null || !isset($catalog[$key])) {
            return null;
        }

        return $this->books->find($catalog[$key]);
    }

    /** The Hardcover search text for one item; empty when MAM gave us nothing to search on. */
    private static function lookupQuery(FreeleechItem $item): string
    {
        return trim(self::cleanTitle($item->getTitle()) . ' ' . ($item->getAuthors()[0] ?? ''));
    }

    /**
     * Score one item's Hardcover candidates and stamp the outcome. True when it resolved to a
     * catalog row; false when nothing cleared the threshold (the item is stamped unmatched and
     * renders from its MAM strings).
     *
     * @param list<TrendingBook>      $candidates
     * @param array<string, Book>     $resolvedBooks slug => Book created earlier in this run
     */
    private function apply(FreeleechItem $item, array $candidates, \DateTimeImmutable $now, array &$resolvedBooks): bool
    {
        $title = self::cleanTitle($item->getTitle());
        $author = $item->getAuthors()[0] ?? '';
        $plan = self::plan($item, $title, $author);

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            if (self::slug($candidate) === null) {
                continue;
            }
            $score = $this->scorer->score(self::candidate($candidate, $item), $plan)->total;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($best === null || $bestScore < self::MATCH_THRESHOLD) {
            $item->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);

            return false;
        }

        /** @var string $slug */
        $slug = self::slug($best);
        $audiobook = $best->audiobook || $item->isAudiobook();
        $book = $resolvedBooks[$slug] ?? null;
        if ($book === null) {
            $book = $this->books->upsertMetadataBook(
                source: Book::SOURCE_HARDCOVER,
                externalId: $slug,
                title: $best->title,
                author: $best->author,
                externalUrl: $best->externalUrl,
                coverUrl: $best->coverUrl,
                rawIsbns: $best->isbns,
                now: $now,
                audiobookAvailable: $audiobook,
            );
            // Flushing per resolution costs nothing next to the paced Hardcover call it follows,
            // and it is what makes the upsert's existence query correct for every later item.
            $this->em->flush();
            $resolvedBooks[$slug] = $book;
        } elseif ($audiobook) {
            // The upsert only ever flips availability on; reusing a book the ebook edition
            // created must not lose the audiobook edition's fact.
            $book->setAudiobookAvailable(true);
        }
        $item->setBook($book);
        $item->setResolution(FreeleechItem::RESOLUTION_RESOLVED);

        return true;
    }

    /**
     * The MAM strings as a search plan. Only title and author are populated: every other
     * category would count against the achievable maximum with nothing on the Hardcover
     * side to match it.
     */
    private static function plan(FreeleechItem $item, string $title, string $author): ReleaseSearchPlan
    {
        $book = new Book('mam', (string) $item->getMamTorrentId(), $title);
        if ($author !== '') {
            $book->setAuthor($author);
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: [],
            author: $author,
            titleVariants: $title !== '' ? [$title] : [],
            contentType: $item->isAudiobook() ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK,
        );
    }

    private static function candidate(TrendingBook $book, FreeleechItem $item): ReleaseCandidate
    {
        return new ReleaseCandidate(
            source: Book::SOURCE_HARDCOVER,
            sourceId: (string) self::slug($book),
            title: $book->title,
            contentType: $item->isAudiobook() ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK,
            author: $book->author,
            isbns: $book->isbns,
        );
    }

    /** MAM appends a "[ENG / EPUB]" bracket to every title; it is noise to the metadata source. */
    private static function cleanTitle(string $title): string
    {
        $cleaned = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/', '', $title));

        return $cleaned !== '' ? $cleaned : trim($title);
    }

    private static function slug(TrendingBook $book): ?string
    {
        $path = parse_url((string) $book->externalUrl, PHP_URL_PATH) ?: '';

        return preg_match('~/books/([^/?#]+)~', $path, $m) === 1 ? $m[1] : null;
    }

    /**
     * HardcoverClient raises one exception type for every transport outcome, so the 429 is
     * only distinguishable by its message ("Hardcover rate-limited the request (HTTP 429).").
     */
    private static function isRateLimited(\Throwable $e): bool
    {
        return $e instanceof HardcoverException
            && (str_contains($e->getMessage(), '429') || stripos($e->getMessage(), 'rate-limited') !== false);
    }

    /**
     * A 429 means every further lookup this quarter-hour would be refused too, so the stamp
     * below lets the five-minute ticks inside the window return without one HTTP request.
     */
    private function parkUntilRateLimitClears(\DateTimeImmutable $now): void
    {
        $state = $this->settings->getMamAccountState();
        $state['resolveBackoffUntil'] = $now
            ->add(new \DateInterval('PT' . self::RESOLVE_BACKOFF_MINUTES . 'M'))
            ->format(\DateTimeInterface::ATOM);
        $this->settings->saveMamAccountState($state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function backoffActive(array $state, \DateTimeImmutable $now): bool
    {
        $until = $state['resolveBackoffUntil'] ?? null;
        if (!is_string($until) || trim($until) === '') {
            return false;
        }

        try {
            $at = new \DateTimeImmutable($until);
        } catch (\Exception) {
            return false;
        }

        return $now->getTimestamp() < $at->getTimestamp();
    }

    private function isDue(Integration $integration): bool
    {
        $last = $integration->getLastSyncAt();
        if ($last === null) {
            return true;
        }
        $minutes = $integration->getSyncIntervalMinutes();
        if ($minutes <= 0) {
            $minutes = self::DEFAULT_INTERVAL_MINUTES;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $last->getTimestamp() >= $minutes * 60;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function ipUpdateDue(array $state, \DateTimeImmutable $now): bool
    {
        $last = $state['lastIpUpdateAt'] ?? null;
        if (!is_string($last) || trim($last) === '') {
            return true;
        }

        try {
            $at = new \DateTimeImmutable($last);
        } catch (\Exception) {
            return true;
        }

        return $now->getTimestamp() - $at->getTimestamp() >= self::IP_UPDATE_INTERVAL_SECONDS;
    }

    /**
     * @return array{skipped: bool, fetched: int, vipFetched: int, new: int, deleted: int, resolved: int, localResolved: int, unmatched: int, pendingLeft: int, error: ?string}
     */
    private static function summary(
        bool $skipped = false,
        int $fetched = 0,
        int $vipFetched = 0,
        int $new = 0,
        int $deleted = 0,
        int $resolved = 0,
        int $localResolved = 0,
        int $unmatched = 0,
        int $pendingLeft = 0,
        ?string $error = null,
    ): array {
        return [
            'skipped'     => $skipped,
            'fetched'     => $fetched,
            'vipFetched'  => $vipFetched,
            'new'         => $new,
            'deleted'     => $deleted,
            'resolved'    => $resolved,
            'localResolved' => $localResolved,
            'unmatched'   => $unmatched,
            'pendingLeft' => $pendingLeft,
            'error'       => $error,
        ];
    }

    /**
     * @return array{skipped: bool, resolved: int, localResolved: int, unmatched: int, deferred: int, pendingLeft: int, error: ?string}
     */
    private static function resolution(
        bool $skipped = false,
        int $resolved = 0,
        int $localResolved = 0,
        int $unmatched = 0,
        int $deferred = 0,
        int $pendingLeft = 0,
        ?string $error = null,
    ): array {
        return [
            'skipped'       => $skipped,
            'resolved'      => $resolved,
            'localResolved' => $localResolved,
            'unmatched'     => $unmatched,
            'deferred'      => $deferred,
            'pendingLeft'   => $pendingLeft,
            'error'         => $error,
        ];
    }
}
