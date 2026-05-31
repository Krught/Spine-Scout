<?php

declare(strict_types=1);

namespace App\Download;

/**
 * Renders an operator-configured filename template (e.g. "{Author} - {Title}
 * ({Year})") into a safe filename. Tokens are case-insensitive; an empty/missing
 * token is removed along with any now-empty surrounding brackets and stray
 * separators, so a book with no year doesn't leave a dangling "()".
 *
 * Pure and side-effect free.
 */
final class FilenameTemplate
{
    private const KNOWN_TOKENS = ['author', 'title', 'year', 'isbn', 'format'];

    /**
     * @param array<string, string|null> $values token name (lowercase) => value
     */
    public function render(string $template, array $values, ?string $extension = null): string
    {
        $base = trim($template) !== '' ? $template : '{Author} - {Title} ({Year})';

        $base = preg_replace_callback(
            '/\{([A-Za-z]+)\}/',
            function (array $m) use ($values): string {
                $key = strtolower($m[1]);
                if (!in_array($key, self::KNOWN_TOKENS, true)) {
                    return $m[0]; // leave unknown tokens untouched
                }

                return trim((string) ($values[$key] ?? ''));
            },
            $base,
        ) ?? $base;

        // Drop brackets left empty by a missing token, collapse whitespace, and
        // trim stray separators from the ends.
        $base = preg_replace('/\(\s*\)|\[\s*\]|\{\s*\}/', '', $base) ?? $base;
        $base = preg_replace('/\s{2,}/', ' ', $base) ?? $base;
        $base = trim($base, " \t\n\r-_.");

        // Strip path separators and characters illegal on common filesystems.
        $base = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#', '', $base) ?? $base;
        $base = trim(preg_replace('/\s{2,}/', ' ', $base) ?? $base, " \t.-_");

        if ($base === '') {
            $base = 'download';
        }

        $ext = $this->normalizeExtension($extension);

        return $ext !== '' ? $base . '.' . $ext : $base;
    }

    private function normalizeExtension(?string $extension): string
    {
        if ($extension === null) {
            return '';
        }

        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $extension) ?? '');
    }
}
