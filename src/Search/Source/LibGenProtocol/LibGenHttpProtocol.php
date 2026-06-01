<?php

declare(strict_types=1);

namespace App\Search\Source\LibGenProtocol;

use App\Repository\BookRepository;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Protocol-shape adapter for the LibGen "library.li / .gs / .com.im"-family HTTP
 * index: a search.php?q=… endpoint that renders div.book-item result cards keyed
 * by an MD5 content hash, a book.php?md5={hash} detail page, and a
 * download.php?md5={hash} endpoint that 302-redirects straight to the file.
 *
 * Pure: builds URLs and parses HTML strings, performs no I/O. The transport,
 * mirror cascade, and ReleaseCandidate mapping live in LibGenSource.
 *
 *   1. GET {base}/search.php?q=…        -> div.book-item cards (md5 + facts)
 *   2. GET {base}/book.php?md5={hash}   -> detail page (ISBNs)
 *      {base}/download.php?md5={hash}   -> the streamable file (redirects to it)
 *
 * NOTE: LibGen markup varies by fork. Parsing is deliberately defensive — a
 * no-results / unexpected / challenge page yields [] (never an exception) so the
 * source can fail over. Older forks (libgen.is/.rs/.st) render a different
 * search.php?req=… table and are NOT handled here; point the mirror at a
 * library.li-family host.
 */
final class LibGenHttpProtocol
{
    /**
     * Build the search URL. TITLE-first (unlike the AA ISBN-first strategy): the
     * library.li-family `q` is a single AND-matched field that does NOT match on
     * ISBN and over-constrains when title + author are concatenated, so the title
     * alone is the reliable query — ISBNs are then verified per-candidate from the
     * book page and relevance is sorted out by scoring. Falls back to the ISBN
     * only when no title is available.
     */
    public function buildSearchUrl(string $baseUrl, ReleaseSearchPlan $plan): string
    {
        $title = trim($plan->primaryTitle());
        $query = $title !== '' ? $title : ($plan->hasIsbn() ? $plan->isbnCandidates[0] : $plan->primaryQuery());

        return $baseUrl . '/search.php?' . http_build_query(['q' => (string) $query]);
    }

    /**
     * Parse the search-results page into records by reading its div.book-item
     * cards. Returns [] when none are present (no results / unexpected page).
     *
     * @return list<LibGenResult>
     */
    public function parseSearchResults(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $cards = (new Crawler($html))->filter('div.book-item');
        if ($cards->count() === 0) {
            return [];
        }

        $results = [];
        $cards->each(function (Crawler $card) use (&$results): void {
            $record = $this->parseCard($card);
            if ($record !== null) {
                $results[] = $record;
            }
        });

        return $results;
    }

    /** The book detail page (carries ISBNs the search card lacks). */
    public function buildDownloadsUrl(string $baseUrl, string $md5): string
    {
        return $baseUrl . '/book.php?md5=' . rawurlencode($md5);
    }

    /** The direct download endpoint (302-redirects to the actual file). */
    public function buildFileUrl(string $baseUrl, string $md5): string
    {
        return $baseUrl . '/download.php?md5=' . rawurlencode($md5);
    }

    /**
     * Best-effort ISBN extraction from a book.php detail page: a whole-page token
     * scan validated through BookRepository::normalizeIsbn.
     *
     * @return list<string>
     */
    public function parseIsbns(string $html): array
    {
        if (trim($html) === '' || !preg_match_all('/[0-9][0-9Xx\-\s]{8,16}[0-9Xx]/', $html, $m)) {
            return [];
        }

        $isbns = [];
        $seen = [];
        foreach ($m[0] as $token) {
            $normalized = BookRepository::normalizeIsbn($token);
            if ($normalized === null || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $isbns[] = $normalized;
        }

        return $isbns;
    }

    public function parseSize(string $size): ?int
    {
        static $units = ['tb' => 1024 ** 4, 'gb' => 1024 ** 3, 'mb' => 1024 ** 2, 'kb' => 1024, 'b' => 1];
        if (!preg_match('/([0-9][0-9.,]*)\s*(tb|gb|mb|kb|b)\b/i', $size, $m)) {
            return null;
        }

        return (int) round((float) str_replace(',', '', $m[1]) * $units[strtolower($m[2])]);
    }

    private function parseCard(Crawler $card): ?LibGenResult
    {
        $md5 = $this->extractMd5($card);
        if ($md5 === null) {
            return null;
        }

        $title = $this->firstText($card, '.book-title a') ?? $this->firstText($card, '.book-title');
        if ($title === null || $title === '') {
            return null;
        }

        $details = $this->parseDetails($card);
        $size = $details['size'] ?? null;

        return new LibGenResult(
            id: $md5,
            title: $title,
            author: $this->firstText($card, '.book-author'),
            publisher: $this->firstText($card, '.book-publisher'),
            year: $details['year'] ?? null,
            language: $details['language'] ?? null,
            format: isset($details['file']) ? strtolower($details['file']) : null,
            size: $size,
            sizeBytes: $size !== null ? $this->parseSize($size) : null,
        );
    }

    /** First 32-hex md5 referenced by any anchor in the card (book/download/cover). */
    private function extractMd5(Crawler $card): ?string
    {
        $found = null;
        $card->filter('a')->each(function (Crawler $a) use (&$found): void {
            if ($found !== null) {
                return;
            }
            if (preg_match('/md5=([0-9a-fA-F]{32})/', (string) $a->attr('href'), $m)) {
                $found = strtolower($m[1]);
            }
        });

        return $found;
    }

    /**
     * Read the book-details spans: labelled "Year: …", "Language: …", "File: …",
     * and the unlabelled size span ("1 MB"). Returns a map keyed year/language/
     * file/size (only those present).
     *
     * @return array<string, string>
     */
    private function parseDetails(Crawler $card): array
    {
        $out = [];
        $spans = $card->filter('.book-details span');
        if ($spans->count() === 0) {
            return $out;
        }

        $spans->each(function (Crawler $span) use (&$out): void {
            $text = trim(preg_replace('/\s+/', ' ', $span->text('')) ?? '');
            if ($text === '') {
                return;
            }
            if (preg_match('/^(year|language|file)\s*:\s*(.+)$/i', $text, $m)) {
                $out[strtolower($m[1])] = trim($m[2]);
            } elseif (!isset($out['size']) && preg_match('/[0-9][0-9.,]*\s*(?:tb|gb|mb|kb|b)\b/i', $text)) {
                $out['size'] = $text;
            }
        });

        return $out;
    }

    private function firstText(Crawler $card, string $selector): ?string
    {
        $node = $card->filter($selector);
        if ($node->count() === 0) {
            return null;
        }
        $text = trim($node->first()->text(''));

        return $text === '' ? null : $text;
    }
}
