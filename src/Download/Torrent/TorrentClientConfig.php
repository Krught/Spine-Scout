<?php

declare(strict_types=1);

namespace App\Download\Torrent;

/**
 * Operator config for the torrent download client and the audiobook destination.
 * The connection (base URL + username/password) lives on the Integration row of
 * kind `qbittorrent` (baseUrl column + credentials); this value object holds the
 * move/destination knobs stored in that row's options['config'] blob.
 *
 * The fulfillment flow is: the download client finishes a torrent into its own
 * completed-downloads dir → Spine Scout reads it under the fixed {@see DOWNLOADS_MOUNT}
 * mount (resolved by basename, so the operator only has to bind-mount that one folder
 * at /downloads) → copies the audio files into a staging dir → moves them into the
 * resolved destination (a dedicated audiobook dir, or the shared ebook library dir
 * when $useEbookLibraryDir is set).
 *
 * Immutable.
 */
final class TorrentClientConfig
{
    public const DEFAULT_CATEGORY = 'spinescout-audiobooks';
    public const DEFAULT_FILENAME_TEMPLATE = '{Author} - {Title}';
    public const DEFAULT_STAGING_SUBDIR = 'torrents';
    public const DEFAULT_AUDIO_OUTPUT_DIRECTORY = '/var/www/html/audiobooks';

    /**
     * Fixed in-container mount for the download client's completed-downloads folder.
     * The operator bind-mounts that single folder here; Spine Scout then finds a
     * finished torrent at /downloads/<basename of the client's content path>, so no
     * per-deploy path translation is needed.
     */
    public const DOWNLOADS_MOUNT = '/downloads';

    /**
     * @param string $category             Download-client category applied to added torrents
     * @param string $audioOutputDirectory Final destination for audiobooks when not reusing the ebook library dir
     * @param bool   $useEbookLibraryDir   When true, deliver into the ebook library output dir instead of $audioOutputDirectory
     * @param string $stagingSubdir        Sub-folder under var/downloads used to stage audio files before the final move
     * @param string $filenameTemplate     Naming template with {Author}/{Title}/{Year}/{ISBN} tokens
     */
    public function __construct(
        public readonly string $category = self::DEFAULT_CATEGORY,
        public readonly string $audioOutputDirectory = self::DEFAULT_AUDIO_OUTPUT_DIRECTORY,
        public readonly bool $useEbookLibraryDir = false,
        public readonly string $stagingSubdir = self::DEFAULT_STAGING_SUBDIR,
        public readonly string $filenameTemplate = self::DEFAULT_FILENAME_TEMPLATE,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed>|null $raw JSON-decoded options['config'] blob
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }

        $str = static function (mixed $v, string $default = ''): string {
            $s = is_string($v) ? trim($v) : '';
            return $s !== '' ? $s : $default;
        };

        return new self(
            $str($raw['category'] ?? null, self::DEFAULT_CATEGORY),
            $str($raw['audioOutputDirectory'] ?? null, self::DEFAULT_AUDIO_OUTPUT_DIRECTORY),
            (bool) ($raw['useEbookLibraryDir'] ?? false),
            $str($raw['stagingSubdir'] ?? null, self::DEFAULT_STAGING_SUBDIR),
            $str($raw['filenameTemplate'] ?? null, self::DEFAULT_FILENAME_TEMPLATE),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category'             => $this->category,
            'audioOutputDirectory' => $this->audioOutputDirectory,
            'useEbookLibraryDir'   => $this->useEbookLibraryDir,
            'stagingSubdir'        => $this->stagingSubdir,
            'filenameTemplate'     => $this->filenameTemplate,
        ];
    }

    /**
     * Map a content path reported by the download client to the path Spine Scout
     * reads it at: the basename under the fixed /downloads mount. The client's own
     * absolute save path (e.g. /mnt/videos/torr/<name>) doesn't matter — only that
     * the operator mounts that completed-downloads folder at /downloads.
     */
    public static function localContentPath(string $clientContentPath): string
    {
        $name = basename(rtrim($clientContentPath, '/'));

        return self::DOWNLOADS_MOUNT . '/' . $name;
    }
}
