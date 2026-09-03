<?php

declare(strict_types=1);

namespace App\Search\Mam;

/**
 * Reads a MyAnonamouse torrent id out of whatever the operator pasted into the
 * Manual Search query fields.
 *
 * Pure string work, no I/O, no config beyond the operator's configured base URL —
 * so every accepted and rejected spelling is unit-testable, and the "is this a
 * MAM link?" question has exactly one answer on the server (the JS does the same
 * detection to decide whether to even ask, but the server never trusts it).
 *
 * Accepted:
 *   https://www.myanonamouse.net/t/182137          (canonical)
 *   https://<configured baseUrl>/t/182137          (whatever the operator saved)
 *   www.myanonamouse.net/t/182137                  (scheme-less paste)
 *   //www.myanonamouse.net/t/182137                (protocol-relative)
 *   /t/182137, t/182137                            (path only — no host to check)
 *   ...with a trailing slash, a `?query`, a `#fragment` or a title slug segment.
 *   182137                                         (bare id) only when $allowBareId
 *
 * Rejected: any other host (a `/t/123` on example.com is NOT a MAM link), any
 * other path, and anything without a positive integer id.
 */
final class MamTorrentLink
{
    /**
     * MAM's own host. The operator's configured base URL is accepted alongside it
     * rather than instead of it: operators routinely point the integration at a
     * proxy/alias while still pasting links copied from the canonical site.
     */
    private const CANONICAL_HOST = 'myanonamouse.net';

    /** Digits a bare (link-less) id may have before it is read as an ISBN instead. */
    private const MAX_BARE_ID_DIGITS = 9;

    /** MAM's torrent detail path. Kept here and in MyAnonamouseClient's page fetch only. */
    private const PATH_PATTERN = '~^/t/(\d+)(?:[/?#].*)?$~';

    /**
     * The torrent id behind $raw, or null when it is not a MAM torrent reference.
     *
     * $allowBareId is the caller saying "MyAnonamouse is the selected source", so
     * a bare number can only mean a torrent id. It is off by default because a
     * bare number typed against any other source is a year, an ISBN fragment or a
     * title — never a MAM id.
     */
    public static function parse(string $raw, string $baseUrl = '', bool $allowBareId = false): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            // A bare number is only ever a torrent id when MAM is the selected
            // source, and even then only up to MAX_BARE_ID_DIGITS: MAM's ids are
            // ~6 digits, while a 10- or 13-digit number typed into a search form is
            // an ISBN, which must keep searching rather than resolving as an id.
            return $allowBareId && \strlen($raw) <= self::MAX_BARE_ID_DIGITS ? self::positive($raw) : null;
        }

        $candidate = $raw;
        if (str_starts_with($candidate, '//')) {
            // Protocol-relative: give parse_url() a scheme so it sees a host at all.
            $candidate = 'https:' . $candidate;
        } elseif (!self::hasScheme($candidate) && !str_starts_with($candidate, '/')) {
            // "www.myanonamouse.net/t/1" vs "t/1": only the former has a host, and
            // the only cheap tell is a dot before the first slash.
            $firstSegment = strstr($candidate, '/', true);
            if (is_string($firstSegment) && str_contains($firstSegment, '.')) {
                $candidate = 'https://' . $candidate;
            }
        }

        if (self::hasScheme($candidate)) {
            $host = parse_url($candidate, PHP_URL_HOST);
            if (!is_string($host) || !self::hostAllowed($host, $baseUrl)) {
                return null;
            }
            $path = parse_url($candidate, PHP_URL_PATH);

            return is_string($path) ? self::idFromPath($path) : null;
        }

        // Path-only: no host to validate, so the path itself has to carry the shape.
        return self::idFromPath(str_starts_with($candidate, '/') ? $candidate : '/' . $candidate);
    }

    /**
     * Whether $raw looks like a MAM torrent reference at all, ignoring the host —
     * the "you pasted a MAM link but picked another source" check, which must fire
     * even when the operator has MAM switched off and therefore has no base URL
     * worth matching against.
     */
    public static function looksLikeTorrentReference(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }
        $path = self::hasScheme($raw) || str_starts_with($raw, '//')
            ? parse_url(str_starts_with($raw, '//') ? 'https:' . $raw : $raw, PHP_URL_PATH)
            : $raw;

        return is_string($path)
            && self::idFromPath(str_starts_with($path, '/') ? $path : '/' . $path) !== null;
    }

    private static function hasScheme(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) === 1;
    }

    private static function idFromPath(string $path): ?int
    {
        // parse_url() already dropped the query/fragment for URL forms; the pattern
        // drops them again for the path-only form, plus any `/title-slug` tail.
        return preg_match(self::PATH_PATTERN, $path, $m) === 1 ? self::positive($m[1]) : null;
    }

    /** Torrent 0 does not exist, and a huge paste must not silently overflow to a real id. */
    private static function positive(string $digits): ?int
    {
        if (!is_numeric($digits) || \strlen(ltrim($digits, '0')) > 18) {
            return null;
        }
        $id = (int) $digits;

        return $id > 0 ? $id : null;
    }

    private static function hostAllowed(string $host, string $baseUrl): bool
    {
        $host = self::normalizeHost($host);
        if ($host === self::CANONICAL_HOST || str_ends_with($host, '.' . self::CANONICAL_HOST)) {
            return true;
        }

        $configured = trim($baseUrl) !== '' ? parse_url(trim($baseUrl), PHP_URL_HOST) : null;

        return is_string($configured) && $configured !== '' && self::normalizeHost($configured) === $host;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        // `www.` is cosmetic on MAM; treating it as significant would reject the
        // exact URL the site's own address bar shows.
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
