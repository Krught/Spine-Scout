<?php

declare(strict_types=1);

namespace App\Search\Source\DirectHttpProtocol;

use App\Repository\BookRepository;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Protocol-shape adapter for "AA-style" HTTP indexers: a search endpoint that
 * returns an HTML results table keyed by a content hash, plus a per-record page
 * that enumerates per-mirror download links.
 *
 * This class is named for the protocol *shape*, never a brand or mirror domain
 * (project ground rule — see shelfmark-research/04). It is intentionally pure:
 * it builds URLs and parses HTML strings, and performs no I/O of its own. The
 * HTTP transport, mirror cascade, and ReleaseCandidate mapping live in
 * DirectHttpSource, which keeps this layer fixture-testable with recorded HTML.
 *
 * The shape mirrors what Shelfmark's direct-download source does internally:
 *   1. GET {base}/search?...&q=... -> results table of records (hash + facts)
 *   2. GET {base}/md5/{hash}       -> page listing concrete download links
 *
 * It is the scraper behind the "Anna's Archive" mirror source
 * (DirectDownloadSource::AnnasArchive) — the one source that performs search.
 */
final class AAStyleHttpProtocol
{

    /**
     * Default file extensions advertised on the search query. Mirrors the
     * BestMatchPolicy default format set so an operator who has not customised
     * anything gets a consistent format universe end to end.
     *
     * @var list<string>
     */
    public const DEFAULT_FORMATS = ['epub', 'mobi', 'azw3', 'pdf', 'cbz', 'cbr'];

    /** Sentinel string the indexer renders when a query matches nothing. */
    private const NO_RESULTS_MARKER = 'No files found.';

    /** Minimum <td> count a results row must have to be a real record row. */
    private const MIN_ROW_CELLS = 11;

    private const SIZE_UNITS = [
        'tb' => 1024 ** 4,
        'gb' => 1024 ** 3,
        'mb' => 1024 ** 2,
        'kb' => 1024,
        'b'  => 1,
    ];

    /**
     * Build the search URL for one mirror base. ISBN-first: when the plan
     * carries any ISBN candidates the query is constrained to those ISBNs (with
     * the title+author kept as a relevance hint); otherwise it falls back to a
     * title+author keyword query tightened with structured term filters.
     *
     * @param string             $baseUrl Mirror base, already normalised (no trailing slash)
     * @param list<string>       $formats Extensions to advertise on `ext=`
     */
    public function buildSearchUrl(string $baseUrl, ReleaseSearchPlan $plan, array $formats = self::DEFAULT_FORMATS): string
    {
        $keyword = trim($plan->primaryQuery());

        if ($plan->hasIsbn()) {
            $isbnExpr = implode(' || ', array_map(
                static fn (string $isbn): string => sprintf("('isbn13:%s' || 'isbn10:%s')", $isbn, $isbn),
                $plan->isbnCandidates,
            ));
            $q = trim(sprintf('(%s) %s', $isbnExpr, $keyword));
        } else {
            $q = $keyword;
        }

        $params = '';
        foreach ($formats as $ext) {
            $ext = trim($ext);
            if ($ext !== '') {
                $params .= '&ext=' . rawurlencode($ext);
            }
        }

        $params .= '&q=' . rawurlencode($q);

        foreach ($plan->languages as $lang) {
            $lang = trim($lang);
            if ($lang !== '' && $lang !== 'all') {
                $params .= '&lang=' . rawurlencode($lang);
            }
        }

        // Without an ISBN, tighten the keyword search with structured term
        // filters so a generic title doesn't drown in unrelated editions.
        if (!$plan->hasIsbn()) {
            $index = 1;
            $title = trim($plan->primaryTitle());
            if ($title !== '') {
                $params .= sprintf('&termtype_%d=title&termval_%d=%s', $index, $index, rawurlencode($title));
                ++$index;
            }
            if (trim($plan->author) !== '') {
                $params .= sprintf('&termtype_%d=author&termval_%d=%s', $index, $index, rawurlencode(trim($plan->author)));
            }
        }

        return $baseUrl
            . '/search?index=&page=1&display=table&acc=aa_download&acc=external_download'
            . $params;
    }

    /**
     * Parse a search-results HTML page into records. Returns an empty list for
     * the "no results" page or when no results table is present (defensive:
     * a mirror that returns a challenge/landing page should yield nothing, not
     * throw, so DirectHttpSource can fail over to the next mirror).
     *
     * @return list<AAStyleResult>
     */
    public function parseSearchResults(string $html): array
    {
        if ($html === '' || str_contains($html, self::NO_RESULTS_MARKER)) {
            return [];
        }

        $crawler = new Crawler($html);
        $tables = $crawler->filter('table');
        if ($tables->count() === 0) {
            return [];
        }

        $results = [];
        $tables->first()->filter('tr')->each(function (Crawler $row) use (&$results): void {
            $record = $this->parseRow($row);
            if ($record !== null) {
                $results[] = $record;
            }
        });

        return $results;
    }

    /** Metadata labels worth surfacing from a record page (lowercased prefixes). */
    private const META_LABEL_PREFIXES = ['isbn', 'asin', 'goodreads', 'language', 'year', 'alternative'];

    /**
     * Build the per-record page URL from which download links are enumerated.
     */
    public function buildDownloadsUrl(string $baseUrl, string $recordId): string
    {
        return $baseUrl . '/md5/' . rawurlencode($recordId);
    }

    /**
     * Parse a record's detail page (/md5/{hash}) for the metadata the matcher
     * cares about — chiefly the verified ISBNs, which the search-results table
     * does not carry. ISBNs are extracted from labelled "ISBN-13 / ISBN-10" rows
     * (mirroring the indexer's codes block) and, failing that, by a whole-page
     * token scan; each is validated/normalised through BookRepository::normalizeIsbn.
     *
     * The `raw` map (label → values, as seen) is returned untouched so the dev
     * probe can show exactly what was parsed when scoring looks wrong.
     *
     * Pure: parses an HTML string, performs no I/O. Never throws.
     *
     * @return array{isbns: list<string>, raw: array<string, list<string>>}
     */
    public function parseRecordMetadata(string $html): array
    {
        $raw = [];
        if (trim($html) !== '') {
            $crawler = new Crawler($html);
            // Each metadata row renders as a small container whose first child is
            // the label and second child the value (nested spans/divs). We read
            // those two cells; the outer block is skipped by the length guard.
            $crawler->filter('div, li, tr')->each(function (Crawler $row) use (&$raw): void {
                $children = $row->children();
                if ($children->count() < 2) {
                    return;
                }
                // The label must be a leaf cell (just text, no nested elements);
                // this skips outer containers whose first child is itself a full
                // label/value row.
                $labelCell = $children->eq(0);
                if ($labelCell->children()->count() !== 0) {
                    return;
                }
                $label = strtolower(trim($labelCell->text('')));
                $value = trim($children->eq(1)->text(''));
                if ($label === '' || $value === '' || \strlen($label) > 40) {
                    return;
                }
                foreach (self::META_LABEL_PREFIXES as $prefix) {
                    if (str_starts_with($label, $prefix)) {
                        $raw[$label][] = $value;

                        return;
                    }
                }
            });
        }

        // Prefer ISBNs from labelled rows; fall back to a whole-page scan so a
        // differently-shaped page still yields something.
        $tokens = [];
        foreach ($raw as $label => $values) {
            if (str_starts_with($label, 'isbn')) {
                foreach ($values as $value) {
                    $tokens = array_merge($tokens, $this->isbnTokens($value));
                }
            }
        }
        if ($tokens === [] && $html !== '') {
            $tokens = $this->isbnTokens($html);
        }

        $isbns = [];
        $seen = [];
        foreach ($tokens as $token) {
            $normalized = BookRepository::normalizeIsbn($token);
            if ($normalized === null || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $isbns[] = $normalized;
        }

        return ['isbns' => $isbns, 'raw' => $raw];
    }

    /**
     * Pull plausible ISBN tokens (10/13-ish runs of digits, optional hyphens,
     * trailing X) out of a string. Over-captures on purpose — normalizeIsbn does
     * the real length/shape validation downstream.
     *
     * @return list<string>
     */
    private function isbnTokens(string $text): array
    {
        if (!preg_match_all('/[0-9][0-9Xx\-\s]{8,16}[0-9Xx]/', $text, $m)) {
            return [];
        }

        return array_values($m[0]);
    }

    /**
     * Enumerate concrete download links from a record page, resolved to
     * absolute URLs against the mirror base, deduped, in document order.
     *
     * Slow-partner and external (get.php?md5=) links are always returned.
     * Fast-partner links are only included when $includeFast is true — they
     * require a paid membership, so they stay off by default (operator opt-in
     * via the "fast downloads" setting).
     *
     * @return list<string>
     */
    public function parseDownloadLinks(string $html, string $baseUrl, bool $includeFast = false): array
    {
        if ($html === '') {
            return [];
        }

        $crawler = new Crawler($html);
        $links = [];
        $seen = [];

        $crawler->filter('a')->each(function (Crawler $anchor) use (&$links, &$seen, $baseUrl, $includeFast): void {
            $href = $anchor->attr('href');
            if ($href === null || trim($href) === '') {
                return;
            }
            $kind = $this->downloadLinkKind($anchor->text(''), $href);
            if ($kind === null || ($kind === self::LINK_FAST && !$includeFast)) {
                return;
            }
            $abs = $this->toAbsoluteUrl($baseUrl, trim($href));
            $key = strtolower($abs);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $links[] = $abs;
        });

        return $links;
    }

    /**
     * AA-site paths/schemes that appear as anchors on the slow-download
     * interstitial but are never the partner-server file link (navigation,
     * account, membership, mirroring). Matched as case-folded substrings.
     *
     * @var list<string>
     */
    private const INTERSTITIAL_SKIP = [
        '/md5/', '/slow_download/', '/fast_download/', '/account', '/donate',
        '/login', '/register', '/member', '/search', '/datasets', '/torrents',
        '/faq', '/contact', '/codes', '/scidb', '/blog', '/about', '/llm',
        '/refer', '/browser_verification', 'mailto:', '/.well-known/',
    ];

    /**
     * Hosts that belong to an anti-bot challenge (DDoS-Guard, Cloudflare,
     * CAPTCHA widgets), never the file. A challenge page embeds these as image
     * beacons / script srcs, so we must not mistake one for the partner link.
     *
     * @var list<string>
     */
    private const CHALLENGE_HOSTS = ['ddos-guard', 'cloudflare', 'recaptcha', 'hcaptcha', 'turnstile', 'gstatic'];

    /**
     * Well-known sites that show up as chrome on the interstitial (footer
     * "about" links, social buttons) but are never a file host — most notably
     * the Wikipedia "Anna's Archive" link, which a naive first-off-mirror-link
     * scan otherwise mistakes for the download. Matched as case-folded host
     * substrings.
     *
     * @var list<string>
     */
    private const NON_PARTNER_HOSTS = [
        'wikipedia.org', 'wikimedia.org', 'reddit.com', 'redd.it', 't.me',
        'telegram.org', 'telegram.me', 'twitter.com', 'x.com', 'facebook.com',
        'github.com', 'github.io', 'githubusercontent.com', 'mastodon',
        'ycombinator.com', 'discord.gg', 'discord.com', 'youtube.com',
        'youtu.be', 'creativecommons.org', 'patreon.com',
    ];

    /**
     * Parse the AA "slow download" interstitial page for the concrete
     * partner-server file URL. That page does not stream the file itself — it
     * names a one-off URL on a partner host (a bare IP or unrelated domain).
     *
     * Selection is by signal strength, not document order (document order is
     * what made a footer Wikipedia link win over the real download):
     *   1. a link that embeds this record's content hash (md5) — AA partner
     *      URLs carry it, so this is the definitive match;
     *   2. otherwise an anchor whose visible text calls itself a download
     *      ("📚 Download now");
     *   3. otherwise the first surviving off-mirror candidate (covers generic
     *      mirrors and the modern copyable-text layout).
     *
     * Returns null when the page carries no such link — e.g. a waitlist
     * countdown page that is still just chrome; the caller waits/retries and
     * then fails over to the next candidate, matching the project ground rule.
     *
     * Pure: parses an HTML string, performs no I/O. Never throws.
     */
    public function parsePartnerDownloadUrl(string $html, string $interstitialUrl): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $mirrorHost = strtolower((string) parse_url($interstitialUrl, PHP_URL_HOST));
        $hash = $this->extractContentHash($interstitialUrl);

        $candidates = $this->collectPartnerCandidates($html, $mirrorHost);

        // 1. Strongest signal: the link references this record's content hash.
        if ($hash !== null) {
            foreach ($candidates as $candidate) {
                if (stripos($candidate['url'], $hash) !== false) {
                    return $candidate['url'];
                }
            }
        }

        // 2. An anchor that announces itself as the download.
        foreach ($candidates as $candidate) {
            if ($candidate['text'] !== '' && preg_match('/down\s*load|descargar|t[eé]l[eé]charger|скачать|⬇|📚/iu', $candidate['text'])) {
                return $candidate['url'];
            }
        }

        // 3. First surviving candidate (junk hosts already filtered out).
        return $candidates[0]['url'] ?? null;
    }

    /**
     * The content hash (md5) embedded in an AA slow/fast-download URL, lowercased,
     * or null. AA hashes are 32 hex chars; we accept >=16 to stay lenient.
     */
    private function extractContentHash(string $url): ?string
    {
        if (preg_match('#/(?:slow|fast)_download/([0-9a-f]{16,})#i', $url, $m)
            || preg_match('#[?&]md5=([0-9a-f]{16,})#i', $url, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Ordered, deduped partner-link candidates from the interstitial HTML:
     * explicit anchors first (document order, carrying their visible text), then
     * any bare URLs that appear only as copyable text. Each is already filtered
     * to a plausible off-mirror partner host by partnerUrlOrNull().
     *
     * @return list<array{url: string, text: string}>
     */
    private function collectPartnerCandidates(string $html, string $mirrorHost): array
    {
        $candidates = [];
        $seen = [];

        (new Crawler($html))->filter('a')->each(function (Crawler $anchor) use (&$candidates, &$seen, $mirrorHost): void {
            $url = $this->partnerUrlOrNull((string) $anchor->attr('href'), $mirrorHost);
            if ($url === null || isset($seen[strtolower($url)])) {
                return;
            }
            $seen[strtolower($url)] = true;
            $candidates[] = ['url' => $url, 'text' => trim($anchor->text(''))];
        });

        if (preg_match_all('#https?://[^\s"\'<>]+#i', $html, $m)) {
            foreach ($m[0] as $raw) {
                $url = $this->partnerUrlOrNull(html_entity_decode($raw), $mirrorHost);
                if ($url === null || isset($seen[strtolower($url)])) {
                    continue;
                }
                $seen[strtolower($url)] = true;
                $candidates[] = ['url' => $url, 'text' => ''];
            }
        }

        return $candidates;
    }

    /**
     * Return $href if it is an absolute http(s) URL on a partner host (off the
     * mirror, not an AA brand domain, not a navigation path), else null.
     */
    private function partnerUrlOrNull(string $href, string $mirrorHost): ?string
    {
        $href = trim($href);
        if ($href === '' || !preg_match('#^https?://#i', $href)) {
            return null;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host === '' || $host === $mirrorHost || str_contains($host, 'annas-archive')) {
            return null;
        }
        foreach (self::CHALLENGE_HOSTS as $challenge) {
            if (str_contains($host, $challenge)) {
                return null;
            }
        }
        foreach (self::NON_PARTNER_HOSTS as $nonPartner) {
            if (str_contains($host, $nonPartner)) {
                return null;
            }
        }

        $lower = strtolower($href);
        foreach (self::INTERSTITIAL_SKIP as $skip) {
            if (str_contains($lower, $skip)) {
                return null;
            }
        }

        return $href;
    }

    private function parseRow(Crawler $row): ?AAStyleResult
    {
        $rowText = strtolower(trim($row->text('')));
        if ($rowText === '' || str_starts_with($rowText, 'your ad here')) {
            return null;
        }

        $cells = $row->filter('td');
        $anchors = $row->filter('a');
        if ($cells->count() < self::MIN_ROW_CELLS || $anchors->count() === 0) {
            return null;
        }

        $href = $anchors->first()->attr('href');
        if ($href === null) {
            return null;
        }
        $parts = explode('/', rtrim($href, '/'));
        $recordId = end($parts);
        if (!is_string($recordId) || $recordId === '') {
            return null;
        }

        $title = $this->cellSpanText($cells, 1);
        if ($title === null) {
            return null;
        }

        $size = $this->cellSpanText($cells, 10);
        $format = $this->cellSpanText($cells, 9);

        return new AAStyleResult(
            id: $recordId,
            title: $title,
            author: $this->cellSpanText($cells, 2),
            publisher: $this->cellSpanText($cells, 3),
            year: $this->cellSpanText($cells, 4),
            language: $this->cellSpanText($cells, 7),
            content: $this->lowerOrNull($this->cellSpanText($cells, 8)),
            format: $this->lowerOrNull($format),
            size: $size,
            sizeBytes: $size !== null ? $this->parseSize($size) : null,
        );
    }

    /** Text of the first <span> inside the nth cell, or null. */
    private function cellSpanText(Crawler $cells, int $index): ?string
    {
        if ($index >= $cells->count()) {
            return null;
        }
        $span = $cells->eq($index)->filter('span');
        if ($span->count() === 0) {
            return null;
        }
        $text = trim($span->first()->text(''));

        return $text === '' ? null : $text;
    }

    private function lowerOrNull(?string $value): ?string
    {
        return $value === null ? null : strtolower($value);
    }

    /**
     * Parse a human-friendly size string ("5.2 MB", "812 KB", "1.3 GB") into
     * bytes. Returns null when no recognisable unit/number is present.
     */
    public function parseSize(string $size): ?int
    {
        if (!preg_match('/([0-9][0-9.,]*)\s*(tb|gb|mb|kb|b)\b/i', $size, $m)) {
            return null;
        }
        $number = (float) str_replace(',', '', $m[1]);
        $unit = strtolower($m[2]);

        return (int) round($number * self::SIZE_UNITS[$unit]);
    }

    private const LINK_SLOW     = 'slow';
    private const LINK_FAST     = 'fast';
    private const LINK_EXTERNAL = 'external';

    /**
     * Classify a download anchor by partner-server speed (or external direct
     * link), or null when it isn't a download link at all. Fast partner servers
     * sit behind a paid membership, so callers gate them separately.
     */
    private function downloadLinkKind(string $text, string $href): ?string
    {
        $text = strtolower(trim($text));
        $hrefLower = strtolower($href);

        if (str_contains($hrefLower, '/fast_download/') || str_contains($text, 'fast partner server')) {
            return self::LINK_FAST;
        }
        if (str_contains($hrefLower, '/slow_download/') || str_starts_with($text, 'slow partner server') || str_contains($text, 'download now')) {
            return self::LINK_SLOW;
        }
        // get.php?...md5=... is the LibGen-shape direct link.
        if (str_contains($hrefLower, 'get.php') && str_contains($hrefLower, 'md5=')) {
            return self::LINK_EXTERNAL;
        }

        return null;
    }

    private function toAbsoluteUrl(string $baseUrl, string $href): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return $baseUrl . $href;
        }

        return $baseUrl . '/' . $href;
    }
}
