<?php

declare(strict_types=1);

namespace App\Search\Interactive;

use App\Download\Mam\MamFulfillment;
use App\Download\Torrent\TorrentFulfillmentInterface;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Integration\MyAnonamouse\MyAnonamouseSettingsProvider;
use App\Integration\Prowlarr\ProwlarrClient;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\DirectDownload\ScoredCandidate;
use App\Search\Mam\MamCandidateMapper;
use App\Search\Mam\MamTorrentLink;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use App\Search\Torrent\ScoredRelease;
use App\Search\Torrent\TorrentMatchPolicy;
use App\Search\Torrent\TorrentMatchScorer;

/**
 * The book-independent half of interactive search: which sources the operator
 * offers, and running ONE of them against ONE mirror with a user-supplied
 * title/author/ISBN, scored.
 *
 * Extracted from InteractiveSearchController so the book-less Manual Search page
 * (ManualSearchController) drives byte-identical search behaviour — same source
 * ordering, same row shapes, same ranking policies — instead of a second copy
 * that would silently drift from it. Nothing here knows about Book, BookRequest
 * or the current user: the caller decides ebook vs audiobook and passes it in as
 * a plain bool (interactive search derives it from the book when the panel
 * doesn't say; manual search only ever has the payload's toggle).
 *
 * Search only — grabbing/downloading a picked row stays in the controllers,
 * because that half genuinely differs (a book request vs. an operator-approved
 * manual filename).
 */
final class InteractiveSearchRunner
{
    /** Server-side cap on returned rows — scoring fetches a detail page per row. */
    public const MAX_RESULTS = 25;

    /** Cap on the category labels echoed per torrent row — the panel shows chips. */
    private const MAX_CATEGORIES = 4;

    public function __construct(
        private readonly DirectDownloadProbe $probe,
        private readonly IntegrationRepository $integrations,
        private readonly ProwlarrClient $prowlarr,
        private readonly TorrentMatchScorer $scorer,
        private readonly TorrentFulfillmentInterface $torrents,
        private readonly MamFulfillment $mamFulfillment,
        private readonly MyAnonamouseClient $mam,
        private readonly MyAnonamouseSettingsProvider $mamSettings,
    ) {
    }

    /**
     * The operator-enabled sources with their configured mirror URLs, so a panel
     * can render the source buttons and the mirror toggle. URLs are never shipped
     * in code — they come entirely from the saved config.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
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

        return $sources;
    }

    /**
     * Run ONE source against ONE mirror with the user-edited title/author/ISBN,
     * scored. Returns every result with its match % and the concrete download
     * links the download/grab step will use.
     *
     * @param array<string, mixed> $payload
     * @param bool                 $audiobook Whether this search targets the audiobook
     *                                        edition — resolved by the caller, because
     *                                        only it knows whether there is a book to
     *                                        fall back to
     */
    public function run(array $payload, bool $audiobook): SearchOutcome
    {
        $sourceId = trim((string) ($payload['source'] ?? ''));
        if (DirectDownloadSource::tryFromId($sourceId) === null) {
            return new SearchOutcome(['error' => 'Unknown source.'], 400);
        }

        // A pasted MyAnonamouse torrent link/id short-circuits the whole search:
        // the operator has already picked the release, so there is nothing to query
        // for and nothing to rank. Kept on this one endpoint (same request, same
        // response shape, single row) so the panel's render/pick/grab path is
        // literally the searched path — see runMamTorrentLink().
        $torrentRef = trim((string) ($payload['torrentUrl'] ?? $payload['torrentId'] ?? ''));
        if ($torrentRef !== '') {
            // Never silently switch sources on the operator: a MAM link resolved
            // "as Anna's Archive" would be a lie about where the file came from.
            if ($sourceId !== DirectDownloadSource::Mam->value) {
                return new SearchOutcome(
                    ['error' => 'That looks like a MyAnonamouse torrent link — pick MyAnonamouse as the source to resolve it.'],
                    400,
                );
            }

            return $this->runMamTorrentLink($torrentRef, $audiobook);
        }

        if ($sourceId === DirectDownloadSource::Torrent->value) {
            return $this->runTorrent($payload, $audiobook);
        }
        if ($sourceId === DirectDownloadSource::Mam->value) {
            return $this->runMam($payload, $audiobook);
        }
        $config = $this->probe->config();

        $mirror = trim((string) ($payload['mirror'] ?? ''));
        if ($mirror === '') {
            $mirror = $config->mirrorsFor($sourceId)->toArray()[0] ?? '';
        }
        if ($mirror === '') {
            return new SearchOutcome(['error' => 'No mirror configured for this source.'], 400);
        }

        $plan = $this->buildPlan($payload);
        if ($audiobook) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        $scored = $this->probe->searchScoredVia($sourceId, $mirror, $plan, $config);
        $rows = array_map(
            static fn (ScoredCandidate $sc): array => self::row($sc),
            array_slice($scored, 0, self::MAX_RESULTS),
        );

        return new SearchOutcome([
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
    private function runTorrent(array $payload, bool $audiobook): SearchOutcome
    {
        if (!$this->torrents->isAvailable()) {
            return new SearchOutcome(['error' => 'Torrent search is not configured.'], 409);
        }

        // The query is always what the user typed into the panel's title/author/
        // ISBN fields — never the stored book metadata, so their edits actually
        // drive the search. The audiobook toggle only stamps the plan's content
        // type (indexer categories key off it), it never swaps the query.
        $plan = $this->buildPlan($payload);
        if ($audiobook && $plan->contentType !== ReleaseCandidate::CONTENT_AUDIOBOOK) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        // Per-search override of the operator's default search method (the panel's
        // Categories / Raw / Filtered toggle); anything unrecognized falls back to
        // the saved default inside search().
        $searchMethod = self::blankToNull($payload['searchMethod'] ?? null);

        $scored = $this->scorer->scored(
            $this->prowlarr->search($plan, $searchMethod),
            $plan,
            $this->integrations->getProwlarrConfig()->matchPolicy(),
        );

        $threshold = $this->probe->matchThreshold();
        $rows = array_map(
            static fn (ScoredRelease $sr): array => self::torrentRow($sr, $threshold, $plan->contentType),
            array_slice($scored, 0, self::MAX_RESULTS),
        );

        return new SearchOutcome([
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
    private function runMam(array $payload, bool $audiobook): SearchOutcome
    {
        if (!$this->mamFulfillment->isAvailable()) {
            return new SearchOutcome(['error' => 'MyAnonamouse search is not configured.'], 409);
        }

        // Same contract as runTorrent(): the query is what the user typed, the
        // audiobook toggle only stamps the plan's content type (MAM's main
        // category keys off it), it never swaps the query.
        $plan = $this->buildPlan($payload);
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

        return new SearchOutcome([
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
     * Resolve ONE pasted MyAnonamouse torrent link ("https://…/t/182137", "/t/182137",
     * or a bare id) into the ordinary run response with a single result row.
     *
     * The row goes through MamCandidateMapper and mamRow() exactly like a searched
     * row, so it carries the same keys — `mam.dlHash` included — and the existing
     * grab path (MamFulfillment, untouched) works on it unchanged.
     *
     * It is NOT run through the scorer. The scorer answers "how well does this
     * release match a query?", and there is no query: the operator named this exact
     * torrent. Worse, the scorer's hard filter drops anything under MAM's seed floor
     * or over the size cap — which would make a directly requested torrent silently
     * vanish. So the row is handed to mamRow() as a perfect, qualifying match, which
     * is what "you asked for this one" means.
     */
    private function runMamTorrentLink(string $reference, bool $audiobook): SearchOutcome
    {
        if (!$this->mamFulfillment->isAvailable()) {
            return new SearchOutcome(['error' => 'MyAnonamouse search is not configured.'], 409);
        }

        $config = $this->mamSettings->getMyAnonamouseConfig();
        // allowBareId: MyAnonamouse is the selected source (checked by the caller),
        // so a bare number can only be a torrent id here.
        $torrentId = MamTorrentLink::parse($reference, $config->baseUrl, allowBareId: true);
        if ($torrentId === null) {
            return new SearchOutcome([
                'error' => 'That is not a MyAnonamouse torrent link — paste something like ' . rtrim($config->baseUrl, '/') . '/t/182137, or just the torrent id.',
            ], 400);
        }

        $lookup = $this->mam->fetchTorrentById($torrentId);
        if (!$lookup->ok()) {
            // Every failure mode (deleted id, invisible to this account, dead
            // session, no dl hash) is already an operator-facing sentence.
            return new SearchOutcome(['error' => $lookup->message], $lookup->httpStatus());
        }

        /** @var \App\Integration\MyAnonamouse\MamRelease $release */
        $release = $lookup->release;
        $candidate = MamCandidateMapper::map($release, $config->baseUrl);
        $scored = new ScoredRelease(
            $candidate,
            1.0,
            ['match' => 1.0, 'seeders' => 1.0, 'size' => 1.0, 'format' => 1.0],
            0,
        );

        $userIsVip = (bool) ($this->mamSettings->getMamAccountState()['isVip'] ?? false);
        $contentType = $audiobook ? ReleaseCandidate::CONTENT_AUDIOBOOK : ReleaseCandidate::CONTENT_EBOOK;

        return new SearchOutcome([
            'source'    => DirectDownloadSource::Mam->value,
            'mirror'    => null,
            // The torrent's own page, so the operator can click straight through to
            // what they pasted instead of a search for it.
            'searchUrl' => rtrim($config->baseUrl, '/') . '/t/' . $torrentId,
            'threshold' => $this->probe->matchThreshold(),
            'truncated' => false,
            'acceptedFormats' => $this->acceptedFormats(),
            'results'   => [self::mamRow($scored, $this->probe->matchThreshold(), $contentType, $userIsVip, $config)],
            // Lets the panel say "resolved" rather than "searched"; harmless to any
            // caller that ignores it.
            'resolvedTorrentId' => $torrentId,
        ]);
    }

    /**
     * The search plan for whatever the user typed. Every field is optional — the
     * manual page routinely posts nothing but a title.
     *
     * @param array<string, mixed> $payload
     */
    private function buildPlan(array $payload): ReleaseSearchPlan
    {
        return $this->probe->buildPlan(
            trim((string) ($payload['isbn'] ?? '')),
            trim((string) ($payload['author'] ?? '')),
            trim((string) ($payload['title'] ?? '')),
            trim((string) ($payload['publisher'] ?? '')),
            trim((string) ($payload['year'] ?? '')),
            trim((string) ($payload['language'] ?? '')),
        );
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
     * Best-effort human-facing MAM search URL for the query the panel just ran —
     * the on-site equivalent of the JSON search, mirroring the mirror sources'
     * `searchUrl`. Null when there is nothing to search for (then the panel simply
     * shows no query link, like the torrent source).
     *
     * Targets MAM's *new* search UI (`/tor/search.php`), which carries its whole
     * state in a single `s` query parameter holding a JSON blob — not the old
     * `/tor/browse.php?tor[text]=` form. This is the human-clickable link only;
     * the actual searching still goes through MyAnonamouseClient against
     * `/tor/js/loadSearchJSONbasic.php`, whose payload uses different key names
     * (`perpage`, `searchType: "all"`) and must NOT be conflated with this blob.
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

        // json_encode + rawurlencode so the blob is escaped correctly; never
        // hand-roll the JSON (titles routinely contain quotes, colons, ampersands).
        // rawurlencode, NOT http_build_query: the latter encodes a space as `+`,
        // which a page doing decodeURIComponent() on the parameter reads back as a
        // literal plus sign — every multi-word query would search the wrong string.
        return rtrim($baseUrl, '/') . '/tor/search.php?s='
            . rawurlencode((string) json_encode(self::mamSearchState($text), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The state object MAM's new search page reads out of its `s` parameter.
     *
     * Shape VERIFIED LIVE 2026-09-03: the operator opened a generated link for
     * "Red Rising Pierce Brown" and MAM returned 29 real matches. The query lives
     * in a nested `com` object ("common" search terms) alongside the field
     * selector, with the paging and result-set keys flat beside it. `searchIn`
     * mirrors the JSON API's `tor[srchIn]` (title/author/narrator), so the link
     * lands on the same field set the automatic search actually queried.
     *
     * Do NOT conflate this with MyAnonamouseClient's payload vocabulary — the API
     * spells the same concepts `perpage` and `searchType: 'all'`.
     *
     * @return array<string, mixed>
     */
    private static function mamSearchState(string $text): array
    {
        return [
            'com' => [
                'text'     => $text,
                'searchIn' => ['title', 'author', 'narrator'],
            ],
            'perPage'    => 20,
            'searchType' => 'Torrents',
        ];
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

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
