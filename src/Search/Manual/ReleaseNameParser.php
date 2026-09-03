<?php

declare(strict_types=1);

namespace App\Search\Manual;

use App\Download\FilenameTemplate;

/**
 * Best-effort structure out of a release/torrent name, for Manual Search — where
 * there is no book to name the file after, only whatever the indexer called it.
 *
 * A release name is not metadata: it is a scene/tracker string that may or may
 * not carry an author, a year, a format and a group tag, in any order, glued
 * with dots or underscores. So this is deliberately a *best effort* with a hard
 * safety rule: when the parse is not confident (no author found, or nothing left
 * of the title), the raw release name is used verbatim rather than a guess. An
 * author is NEVER invented — a name that yields only a title renders as the raw
 * string, because "{Author} - {Title}" with a fabricated author is worse than an
 * ugly filename.
 *
 * The rendered name is only a *suggestion*: Manual Search shows it in an
 * editable field and the operator's final text is what actually hits the disk
 * (ManualSearchController::deliveryName → sanitize()).
 *
 * Pure and side-effect free apart from FilenameTemplate, which is itself pure.
 */
final class ReleaseNameParser
{
    /**
     * Extensions stripped from the tail of a release name (and recognized as the
     * format). Kept local rather than reusing EbookFormat/AudioFormat: this is a
     * *string-shape* question ("does this tail look like a file extension?"), not
     * a question about what the pipeline accepts.
     */
    private const FILE_EXTENSIONS = [
        'epub', 'mobi', 'azw', 'azw3', 'pdf', 'djvu', 'cbz', 'cbr', 'fb2', 'lit', 'txt', 'rtf',
        'm4b', 'm4a', 'mp3', 'flac', 'ogg', 'opus', 'aac', 'wma', 'wav',
    ];

    /**
     * Standalone tokens that describe the *file*, not the work — dropped from the
     * title. Only whole tokens are matched, so "Retaliation" survives "retail".
     */
    private const NOISE_TOKENS = [
        'epub', 'mobi', 'azw', 'azw3', 'pdf', 'djvu', 'cbz', 'cbr', 'fb2', 'lit', 'rtf',
        'm4b', 'm4a', 'mp3', 'flac', 'ogg', 'opus', 'aac', 'wma', 'wav',
        'retail', 'proper', 'repack', 'remux', 'scan', 'scanned', 'ocr', 'rescan',
        'web', 'webrip', 'webdl', 'hdtv', 'dvd', 'cd', 'vbr', 'stereo', 'mono',
        'unabridged', 'abridged', 'audiobook', 'audiobooks', 'ebook', 'ebooks',
        'complete', 'retailmm', 'v2', 'v3', 'nfo',
    ];

    /**
     * Lowercase name particles that do not break the "looks like a person" test —
     * "Ursula K. Le Guin", "Cixin Liu", "Herman van Veen".
     */
    private const NAME_PARTICLES = ['de', 'del', 'della', 'di', 'du', 'la', 'le', 'van', 'von', 'der', 'den', 'ter', 'bin', 'al', 'y', 'e'];

    /** Words that mark a side of an "A - B" split as a title rather than a person. */
    private const TITLE_LEAD_WORDS = ['the', 'a', 'an', 'my', 'his', 'her', 'their', 'our', 'this', 'that', 'how', 'why', 'what'];

    public function __construct(
        private readonly FilenameTemplate $filenames,
    ) {
    }

    /**
     * The name to offer the operator for this release: the operator's own filename
     * template when the parse found both an author and a title, otherwise the raw
     * release name (sanitized, never re-shaped).
     *
     * @param string  $template  The operator's template for the target medium —
     *                           DirectDownloadConfig::filenameTemplate for an ebook
     *                           file, TorrentClientConfig::filenameTemplate for an
     *                           audiobook album folder
     * @param ?string $extension Appended to the rendered name; null for an audiobook
     *                           folder, which has no extension
     */
    public function suggest(string $release, string $template, ?string $extension = null): string
    {
        $parsed = self::parse($release);

        if (!$parsed['confident']) {
            return self::sanitize($release, $extension);
        }

        return $this->filenames->render($template, [
            'author' => $parsed['author'],
            'title'  => $parsed['title'],
            'year'   => $parsed['year'],
            // There is no book, so there is no ISBN to render — the token simply
            // drops out (along with its brackets) the way a missing year does.
            'isbn'   => null,
            'format' => $extension ?? $parsed['format'],
        ], $extension);
    }

    /**
     * @return array{author: ?string, title: string, year: ?string, format: ?string, confident: bool}
     */
    public static function parse(string $release): array
    {
        $name = trim($release);
        if ($name === '') {
            return ['author' => null, 'title' => '', 'year' => null, 'format' => null, 'confident' => false];
        }

        $format = null;
        $year = null;

        // 1. A trailing file extension is the format, not part of the title.
        if (preg_match('/\.([A-Za-z0-9]{2,5})$/', $name, $m) === 1
            && in_array(strtolower($m[1]), self::FILE_EXTENSIONS, true)) {
            $format = strtolower($m[1]);
            $name = substr($name, 0, -\strlen($m[0]));
        }

        // 2. Bracketed/parenthesised/braced groups are tag dumps ([EPUB], (retail),
        //    {M4B}, (2019)) — pulled out wholesale, then mined for a format/year.
        $tags = [];
        if (preg_match_all('/[\[\(\{]([^\[\]\(\)\{\}]*)[\]\)\}]/u', $name, $mm) > 0) {
            $tags = $mm[1];
            $name = (string) preg_replace('/[\[\(\{][^\[\]\(\)\{\}]*[\]\)\}]/u', ' ', $name);
        }
        foreach ($tags as $tag) {
            foreach (preg_split('/[^A-Za-z0-9]+/', $tag) ?: [] as $token) {
                if ($format === null && in_array(strtolower($token), self::FILE_EXTENSIONS, true)) {
                    $format = strtolower($token);
                }
                if ($year === null && self::isYear($token)) {
                    $year = $token;
                }
            }
        }

        // 3. Scene separators. A name with no spaces at all is dot/underscore-glued
        //    ("Pierce.Brown-Red.Rising.2014.EPUB"), so both become spaces; a name
        //    that already has spaces keeps its dots (initials, "Vol. 2") and only
        //    loses underscores, which are never meaningful in a title.
        $glued = !str_contains($name, ' ');
        $name = $glued
            ? (string) preg_replace('/[._]+/', ' ', $name)
            : str_replace('_', ' ', $name);

        // 4. A trailing group tag hangs off the last word with no space around the
        //    dash ("...Red Rising-KTR"). Requiring upper-case keeps "Something-two"
        //    and hyphenated words intact.
        $name = (string) preg_replace('/(?<=\S)-[A-Z0-9]{2,10}$/', '', $name);

        // 4b. In a dot-glued scene name the author/title separator is a bare dash
        //     ("Pierce.Brown-Red.Rising"), which step 7 would not see. Only promoted
        //     to a separator when there is exactly one dash AND its left side is
        //     multi-word — otherwise "Jean-Luc.Picard.Memoirs" and "Sci-Fi.Reader"
        //     would be torn apart at a hyphen that belongs to the words themselves.
        if ($glued && substr_count($name, '-') === 1) {
            [$left, $right] = explode('-', $name, 2);
            if (str_contains(trim($left), ' ') && trim($right) !== '') {
                $name = trim($left) . ' - ' . trim($right);
            }
        }

        // 5. A standalone 4-digit year is metadata, not title text — but only when
        //    something else survives, so the release "1984" stays "1984".
        if (preg_match('/(?<![0-9])((?:19|20)\d{2})(?![0-9])/', $name, $ym) === 1) {
            $without = trim((string) preg_replace('/(?<![0-9])' . preg_quote($ym[1], '/') . '(?![0-9])/', ' ', $name));
            if (self::squash($without) !== '') {
                $year ??= $ym[1];
                $name = $without;
            }
        }

        // 6. Drop file-describing noise tokens (whole tokens only) and bitrates.
        $kept = [];
        foreach (preg_split('/\s+/', $name) ?: [] as $token) {
            $bare = strtolower(trim($token, ".,;:'\"!"));
            if ($bare === '') {
                continue;
            }
            if (in_array($bare, self::NOISE_TOKENS, true) || preg_match('/^\d{2,3}k(bps)?$/', $bare) === 1) {
                continue;
            }
            $kept[] = $token;
        }
        $name = self::squash(implode(' ', $kept));

        // 7. Split "Author - Title" / "Title - Author". No dash → no author: we
        //    never invent one, so such a release renders as its raw name.
        $author = null;
        $title = $name;
        $parts = array_values(array_filter(
            array_map(static fn (string $p): string => self::squash($p), preg_split('/\s+[-–—]\s+/u', $name) ?: []),
            static fn (string $p): bool => $p !== '',
        ));
        if (\count($parts) >= 3) {
            // "Author - Series 02 - Title": the outer ends are the interesting
            // ones, and the author is conventionally first.
            $author = self::isPersonName($parts[0]) ? $parts[0] : null;
            $title = $author !== null ? $parts[\count($parts) - 1] : $name;
        } elseif (\count($parts) === 2) {
            [$left, $right] = $parts;
            $leftPerson = self::isPersonName($left);
            $rightPerson = self::isPersonName($right);
            if ($leftPerson && !$rightPerson) {
                $author = $left;
                $title = $right;
            } elseif ($rightPerson && !$leftPerson) {
                $author = $right;
                $title = $left;
            } elseif ($leftPerson && $rightPerson) {
                // Both could be a person (two capitalised short phrases). The
                // "Author - Title" convention wins unless the left half reads like
                // a title ("The Long Way Down - Pierce Brown").
                if (self::looksLikeTitle($left) && !self::looksLikeTitle($right)) {
                    $author = $right;
                    $title = $left;
                } else {
                    $author = $left;
                    $title = $right;
                }
            }
            // Neither half looks like a person: keep the whole string as the title.
        }

        $title = self::squash(trim($title, " \t-–—_.,"));

        return [
            'author'    => $author,
            'title'     => $title,
            'year'      => $year,
            'format'    => $format,
            'confident' => $author !== null && $title !== '',
        ];
    }

    /**
     * Make an operator-supplied (or raw release) name safe to write into the
     * destination directory: no path separators, no directory traversal, no
     * characters illegal on common filesystems — the same shape FilenameTemplate
     * produces and FileMover's basename() expects, so a manual name can never
     * escape the library folder.
     *
     * @param ?string $extension Ensured exactly once at the tail (a name that
     *                           already ends in it is not doubled up)
     */
    public static function sanitize(string $name, ?string $extension = null): string
    {
        // basename() first: it is what turns "../../etc/passwd" into "passwd".
        $base = basename(str_replace('\\', '/', trim($name)));
        $base = (string) preg_replace('#[:*?"<>|\x00-\x1F]#', '', $base);
        // Collapse dot runs — this is what disarms a bare ".." and "a..b".
        $base = (string) preg_replace('/\.{2,}/', '.', $base);
        $base = self::squash($base);
        $base = trim($base, " \t.-_");
        if ($base === '') {
            $base = 'download';
        }
        // Common filesystems cap a single path component at 255 bytes; leave room
        // for the extension and FileMover's " (1)" collision suffix.
        $base = mb_substr($base, 0, 180);

        $ext = $extension === null ? '' : strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $extension));
        if ($ext === '') {
            return $base;
        }
        if (str_ends_with(strtolower($base), '.' . $ext)) {
            return $base;
        }

        return $base . '.' . $ext;
    }

    /**
     * Whether this phrase reads like a person's name: one to four words, each
     * capitalised (or a lowercase particle), no digits. Deliberately strict —
     * a false positive here mislabels the title as the author.
     */
    private static function isPersonName(string $phrase): bool
    {
        $words = preg_split('/\s+/', trim($phrase)) ?: [];
        if ($words === [] || \count($words) > 4) {
            return false;
        }

        foreach ($words as $word) {
            $word = trim($word, ",'\"");
            if ($word === '' || preg_match('/\d/', $word) === 1) {
                return false;
            }
            if (in_array(mb_strtolower($word), self::NAME_PARTICLES, true)) {
                continue;
            }
            if (preg_match('/^\p{Lu}[\p{L}\'’.\-]*$/u', $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** A leading article, a colon, or more than four words all read as "title". */
    private static function looksLikeTitle(string $phrase): bool
    {
        $words = preg_split('/\s+/', trim($phrase)) ?: [];

        return \count($words) > 4
            || str_contains($phrase, ':')
            || ($words !== [] && in_array(mb_strtolower($words[0]), self::TITLE_LEAD_WORDS, true));
    }

    private static function isYear(string $token): bool
    {
        return preg_match('/^(?:19|20)\d{2}$/', $token) === 1;
    }

    private static function squash(string $value): string
    {
        return trim((string) preg_replace('/\s{2,}/', ' ', $value));
    }
}
