<?php

declare(strict_types=1);

namespace App\Mirror;

/**
 * Cleans up operator-supplied mirror URLs: trim, default scheme to https,
 * strip trailing slash, drop blanks, dedupe while preserving order.
 *
 * Stateless. Safe to inject anywhere.
 */
final class MirrorListNormalizer
{
    /**
     * Normalize a free-text blob of mirror URLs pasted into a textarea. URLs may
     * be separated by any whitespace (newlines, tabs, spaces) or commas. Result
     * is the same normalized, deduped, order-preserving list as normalize().
     *
     * @return list<string>
     */
    public function normalizeBlob(string $blob): array
    {
        $parts = preg_split('/[\s,]+/', $blob);

        return $this->normalize($parts === false ? [] : $parts);
    }

    /**
     * @param iterable<mixed> $urls
     * @return list<string>
     */
    public function normalize(iterable $urls): array
    {
        $out = [];
        $seen = [];
        foreach ($urls as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $url = trim($raw);
            if ($url === '') {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                $url = 'https://' . $url;
            }
            $url = rtrim($url, '/');
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $url;
        }
        return $out;
    }
}
