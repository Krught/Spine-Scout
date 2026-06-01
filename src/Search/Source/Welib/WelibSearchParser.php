<?php

declare(strict_types=1);

namespace App\Search\Source\Welib;

use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\DirectHttpProtocol\AAStyleResult;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Search-results parser for Welib. Welib began as an Anna's-Archive-shaped index
 * but its /search page has since diverged from the AA results *table*: it now
 * renders each hit as an `.book-card` <div> (the AA "aarecord-list" card layout)
 * and ignores the &display=table hint entirely. AAStyleHttpProtocol's table
 * parser therefore finds no <table> and returns nothing, even when the page is
 * full of results — the "Parsed 0 records" the probe reported. This parser reads
 * the card layout instead, mapping each card onto the same AAStyleResult the
 * rest of the Welib source already consumes.
 *
 * Welib's /md5/{hash} record page is still AA-shaped (slow/fast_download links),
 * so only search-results parsing needs this; detail + link extraction stay on
 * AAStyleHttpProtocol. Pure/no-I/O like the protocol it complements, so it stays
 * fixture-testable against recorded HTML.
 */
final class WelibSearchParser
{
    /** Sentinel Welib renders when a query matches nothing. */
    private const NO_RESULTS_MARKER = 'No files found.';

    /** The "·" separator Welib prefixes onto the metadata spans after the first. */
    private const BULLET = "\u{00b7}";

    public function __construct(private readonly AAStyleHttpProtocol $protocol)
    {
    }

    /**
     * Parse a Welib search-results page into records. Empty list for the
     * "no results" page or any page without result cards (a challenge/landing
     * page yields nothing rather than throwing, so the source can fail over).
     *
     * @return list<AAStyleResult>
     */
    public function parseSearchResults(string $html): array
    {
        if ($html === '' || str_contains($html, self::NO_RESULTS_MARKER)) {
            return [];
        }

        $cards = (new Crawler($html))->filter('.book-card');
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

    private function parseCard(Crawler $card): ?AAStyleResult
    {
        $hash = $this->recordHash($card);
        if ($hash === null) {
            return null;
        }

        $title = $this->firstText($card, 'h2') ?? $this->imgAttr($card, 'data-title');
        if ($title === null) {
            return null;
        }

        $spans = $this->metaSpans($card);
        $meta = $this->classifyMeta($spans);
        $size = $meta['size'];

        return new AAStyleResult(
            id: $hash,
            title: $title,
            author: $this->author($card),
            publisher: null,
            year: $meta['year'],
            language: $meta['language'],
            content: null,
            format: $this->formatFrom($spans),
            size: $size,
            sizeBytes: $size !== null ? $this->protocol->parseSize($size) : null,
        );
    }

    /** The record's content hash, from the first /md5/{hash} anchor in the card. */
    private function recordHash(Crawler $card): ?string
    {
        foreach ($card->filter('a') as $anchor) {
            /** @var \DOMElement $anchor */
            if (preg_match('#/md5/([0-9a-fA-F]{16,})#', (string) $anchor->getAttribute('href'), $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /** Author from the cover img's data-author (Welib appends a trailing "; "), else the byline anchor. */
    private function author(Crawler $card): ?string
    {
        $author = $this->imgAttr($card, 'data-author');
        if ($author === null) {
            $anchor = $card->filter('p a[href*="/search?q="]');
            $author = $anchor->count() > 0 ? trim($anchor->first()->text('')) : null;
        }
        if ($author === null) {
            return null;
        }

        $author = trim(rtrim($author, '; '));

        return $author === '' ? null : $author;
    }

    /**
     * The metadata row's span texts (format, language, year, size), each with its
     * leading "·" separator stripped. Empty texts are dropped.
     *
     * @return list<string>
     */
    private function metaSpans(Crawler $card): array
    {
        $spans = [];
        $card->filter('div.mb-1 span')->each(function (Crawler $span) use (&$spans): void {
            $text = $this->stripBullet($span->text(''));
            if ($text !== '') {
                $spans[] = $text;
            }
        });

        return $spans;
    }

    /**
     * Extension from the first metadata span. Welib shows e.g. "epub · PDF" for a
     * record exposed in more than one format, so take the leading token.
     *
     * @param list<string> $spans
     */
    private function formatFrom(array $spans): ?string
    {
        if ($spans === []) {
            return null;
        }
        $first = trim(explode(self::BULLET, $spans[0])[0]);

        return $first === '' ? null : strtolower($first);
    }

    /**
     * Classify the remaining metadata spans by shape rather than fixed position,
     * so a record missing one (no year, say) still maps the rest correctly.
     *
     * @param list<string> $spans
     *
     * @return array{year: ?string, language: ?string, size: ?string}
     */
    private function classifyMeta(array $spans): array
    {
        $year = $language = $size = null;
        foreach (array_slice($spans, 1) as $text) {
            if ($size === null && preg_match('/\d[\d.,]*\s*(tb|gb|mb|kb|b)\b/i', $text)) {
                $size = $text;
            } elseif ($year === null && preg_match('/^(1[5-9]\d\d|20\d\d)$/', $text)) {
                $year = $text;
            } elseif ($language === null && preg_match('/^[\p{L}][\p{L} ,\/;.-]*$/u', $text)) {
                $language = $text;
            }
        }

        return ['year' => $year, 'language' => $language, 'size' => $size];
    }

    private function stripBullet(string $text): string
    {
        return trim((string) preg_replace('/^[\s\x{00b7}]+/u', '', trim($text)));
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

    private function imgAttr(Crawler $card, string $attr): ?string
    {
        $img = $card->filter('img');
        if ($img->count() === 0) {
            return null;
        }
        $value = $img->first()->attr($attr);

        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
