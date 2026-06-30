<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Classifies a file-format token (extension or MIME subtype) as an audiobook format.
 *
 * The owned format is captured from Komga during library sync onto {@see \App\Entity\Book::$format};
 * this is the single place that decides whether a given owned copy counts as an audiobook vs an
 * ebook, so the Browse page's format toggle and "downloaded" badge stay consistent.
 */
final class AudioFormat
{
    /** Lowercased audio extensions / MIME subtypes. Covers Audible (aax/aaxc) and common containers. */
    public const EXTENSIONS = [
        'mp3', 'mpeg', 'm4a', 'm4b', 'mp4', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wav', 'wave', 'aax', 'aaxc',
    ];

    public static function isAudio(?string $format): bool
    {
        if ($format === null || $format === '') {
            return false;
        }
        return in_array(strtolower($format), self::EXTENSIONS, true);
    }
}
