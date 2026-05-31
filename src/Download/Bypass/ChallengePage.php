<?php

declare(strict_types=1);

namespace App\Download\Bypass;

/**
 * Sniffs whether a fetched body is an anti-bot challenge / interstitial
 * (Cloudflare "just a moment", DDoS-Guard) rather than real content. Used to (a)
 * decide a bypass pass didn't actually get through, and (b) make sure the
 * download client never streams a challenge page to disk as if it were the file.
 *
 * Markers are specific enough not to collide with the (binary) head of a real
 * ebook — a PDF/EPUB head holds none of these ASCII strings.
 */
final class ChallengePage
{
    /** @var list<string> Lowercased substrings that signal a challenge page. */
    private const MARKERS = [
        'ddos-guard',
        'check.ddos-guard.net',
        '/.well-known/ddos-guard',
        'just a moment',
        'cf-browser-verification',
        'window._cf_chl',
        '_cf_chl_opt',
        'attention required! | cloudflare',
        'enable javascript and cookies to continue',
        'challenge-platform',
        'turnstile',
    ];

    public static function looksLikeChallenge(string $body): bool
    {
        if (trim($body) === '') {
            return false;
        }
        $haystack = strtolower($body);
        foreach (self::MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
