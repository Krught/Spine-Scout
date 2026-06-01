<?php

declare(strict_types=1);

namespace App\Search\Source\ZLibraryProtocol;

use App\Repository\BookRepository;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Protocol-shape adapter for a Z-Library-style HTTP index: a /s/{query} search
 * page that renders <z-bookcard> result cards linking to a /book/… page, whose
 * page carries the concrete /dl/… download link.
 *
 * Pure: builds URLs and parses HTML strings, performs no I/O. The transport,
 * mirror cascade, and ReleaseCandidate mapping live in ZLibrarySource.
 *
 *   1. GET {base}/s/{query}        -> <z-bookcard> cards (book path + facts)
 *   2. GET {base}{book path}       -> page carrying the /dl/… download link
 *
 * NOTE: Z-Library markup varies and some mirrors gate results. Parsing is
 * deliberately defensive — an unexpected / gated / challenge page yields []
 * (never an exception) so the source can fail over. The bundled fixtures model
 * the <z-bookcard> shape and should be re-captured from a live mirror before
 * relying on edge cases.
 */
final class ZLibraryHttpProtocol
{
    private const SIZE_UNITS = [
        'tb' => 1024 ** 4,
        'gb' => 1024 ** 3,
        'mb' => 1024 ** 2,
        'kb' => 1024,
        'b'  => 1,
    ];

    /**
     * Build the search URL for one mirror base. ISBN-first: an ISBN query when
     * present, otherwise title + author. Z-Library carries the query in the path.
     */
    public function buildSearchUrl(string $baseUrl, ReleaseSearchPlan $plan): string
    {
        $query = $plan->hasIsbn() ? $plan->isbnCandidates[0] : $plan->primaryQuery();

        return $baseUrl . '/s/' . rawurlencode((string) $query);
    }

    /**
     * Parse a search-results page into records by reading its <z-bookcard> cards.
     * Returns [] when none are present (gated / unexpected / challenge page).
     *
     * @return list<ZLibraryResult>
     */
    public function parseSearchResults(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $cards = (new Crawler($html))->filter('z-bookcard');
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

    /** Resolve a book-page path against the mirror base to an absolute URL. */
    public function buildBookUrl(string $baseUrl, string $bookPath): string
    {
        return $this->toAbsoluteUrl($baseUrl, $bookPath);
    }

    /**
     * Extract concrete download links from a book page, resolved to absolute URLs
     * against the mirror base, deduped, in document order. The download control is
     * an anchor whose href is a /dl/ path (or whose visible text announces a
     * download). Returns [] when none is present (login-gated / unexpected page).
     *
     * @return list<string>
     */
    public function parseDownloadLinks(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $links = [];
        $seen = [];
        (new Crawler($html))->filter('a')->each(function (Crawler $a) use (&$links, &$seen, $baseUrl): void {
            $href = trim((string) $a->attr('href'));
            if ($href === '') {
                return;
            }
            $isDownload = str_contains(strtolower($href), '/dl/')
                || preg_match('/down\s*load/i', $a->text('')) === 1;
            if (!$isDownload) {
                return;
            }
            $abs = $this->toAbsoluteUrl($baseUrl, $href);
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
     * Best-effort ISBN extraction from a book page: a whole-page token scan
     * validated through BookRepository::normalizeIsbn.
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
        if (!preg_match('/([0-9][0-9.,]*)\s*(tb|gb|mb|kb|b)\b/i', $size, $m)) {
            return null;
        }

        return (int) round((float) str_replace(',', '', $m[1]) * self::SIZE_UNITS[strtolower($m[2])]);
    }

    private function parseCard(Crawler $card): ?ZLibraryResult
    {
        $href = trim((string) $card->attr('href'));
        if ($href === '') {
            return null;
        }

        $title = $this->slotText($card, 'title');
        if ($title === null || $title === '') {
            return null;
        }

        $size = $this->blankToNull((string) $card->attr('filesize'));
        $format = $this->blankToNull((string) $card->attr('extension'));

        return new ZLibraryResult(
            id: $this->idFromHref($href),
            bookPath: $href,
            title: $title,
            author: $this->slotText($card, 'author'),
            publisher: $this->blankToNull((string) $card->attr('publisher')),
            year: $this->blankToNull((string) $card->attr('year')),
            language: $this->blankToNull((string) $card->attr('language')),
            format: $format !== null ? strtolower($format) : null,
            size: $size,
            sizeBytes: $size !== null ? $this->parseSize($size) : null,
        );
    }

    /** Text of the card's `<div slot="...">` child, or null. */
    private function slotText(Crawler $card, string $slot): ?string
    {
        $node = $card->filter(sprintf('[slot="%s"]', $slot));
        if ($node->count() === 0) {
            return null;
        }

        return $this->blankToNull(trim($node->first()->text('')));
    }

    /** Stable id from the book-page href (its numeric/hash segments). */
    private function idFromHref(string $href): string
    {
        if (preg_match('~/book/([^/?#]+(?:/[^/?#]+)?)~', $href, $m)) {
            return $m[1];
        }

        return trim($href, '/');
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
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
