<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Classifies a file-format token (extension) as an ebook format, and ranks the
 * common ones so a torrent containing several files can pick the best ebook to
 * deliver. Mirrors {@see AudioFormat} for the book side of torrent fulfillment.
 */
final class EbookFormat
{
    /**
     * Lowercased ebook extensions, best → least preferred. The order doubles as the
     * pick priority when a torrent bundles multiple formats.
     *
     * @var list<string>
     */
    public const EXTENSIONS = ['epub', 'azw3', 'azw', 'mobi', 'pdf', 'fb2', 'djvu', 'cbz', 'cbr', 'txt'];

    public static function isEbook(?string $format): bool
    {
        if ($format === null || $format === '') {
            return false;
        }

        return in_array(strtolower($format), self::EXTENSIONS, true);
    }

    /** Lower is better; unknown formats sort last. */
    public static function rank(string $format): int
    {
        $i = array_search(strtolower($format), self::EXTENSIONS, true);

        return $i === false ? \count(self::EXTENSIONS) : $i;
    }
}
