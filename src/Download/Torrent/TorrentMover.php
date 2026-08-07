<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Support\AudioFormat;

/**
 * Moves a finished audiobook torrent out of qBittorrent's completed folder into
 * the library, in the two stages the operator flow describes: copy the payload
 * into a Spine Scout staging dir, then move that folder into the final
 * destination. We copy (never move) from qBittorrent's dir so the torrent keeps
 * seeding.
 *
 * The whole release folder is preserved: every regular file is staged with its
 * relative path intact (multi-disc CD1/CD2 trees keep their structure), so
 * companion files (.cue/.nfo/.pdf/artwork) travel with the audio. The payload
 * must still contain at least one audio file or the move is refused. After
 * staging, cover art is normalized for Grimmory's folder-cover fallback, which
 * only recognizes a root-level cover/folder/image.{jpg,jpeg,png,webp,gif,bmp}:
 * when no such file exists at the staged root, the largest image anywhere in
 * the tree is copied (original kept) to the root as cover.<ext>. Collision-safe
 * and resilient to cross-device moves. Throws on any failure so the caller
 * marks the job errored.
 */
final class TorrentMover
{
    /** Extensions Grimmory's folder-cover fallback accepts (lowercase). */
    private const COVER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];

    /** Root-level base names Grimmory's folder-cover fallback accepts (lowercase). */
    private const COVER_BASENAMES = ['cover', 'folder', 'image'];

    public function __construct(private readonly string $stagingBaseDir)
    {
    }

    /**
     * @return list<string> Absolute paths of audio files under $path (recursive).
     *                      A single audio file returns just itself.
     */
    public static function audioFiles(string $path): array
    {
        return self::filesMatching($path, static fn (string $p): bool => self::isAudioFile($p));
    }

    /**
     * Absolute paths of files under $path (recursive) that satisfy $accept. A single
     * matching file returns just itself.
     *
     * @param callable(string): bool $accept
     *
     * @return list<string>
     */
    public static function filesMatching(string $path, callable $accept): array
    {
        if (is_file($path)) {
            return $accept($path) ? [$path] : [];
        }
        if (!is_dir($path)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $accept($file->getPathname())) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    public static function isAudioFile(string $path): bool
    {
        return AudioFormat::isAudio(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Stage the whole payload from $sourcePath (every regular file, relative paths
     * preserved; a single-file payload lands at the staged-folder root), normalize
     * cover art, then move the staged folder into $destDir under a folder named
     * $folderName. Returns the final folder path.
     *
     * Refuses payloads with no audio files at all — that would not be an audiobook.
     *
     * @param string $jobKey A unique-per-job token so concurrent moves don't collide in staging.
     * @param callable(string): void|null $beforeFinalize Invoked with the staged folder path
     *                                                    after staging and cover normalization,
     *                                                    before the final rename — e.g. to
     *                                                    rewrite tags on the staged tree. A
     *                                                    throwing callback aborts the move: the
     *                                                    staging dir is cleaned up and the
     *                                                    exception is rethrown unchanged.
     */
    public function move(string $sourcePath, string $destDir, string $folderName, string $jobKey, ?callable $beforeFinalize = null): string
    {
        if (self::audioFiles($sourcePath) === []) {
            throw new \RuntimeException("No audio files found in torrent payload: {$sourcePath}");
        }

        $destDir = rtrim(trim($destDir), '/');
        if ($destDir === '') {
            throw new \RuntimeException('No audiobook destination directory configured.');
        }

        $folder = $this->safeName($folderName);

        // -- Stage: copy the payload into var/downloads/<staging>/<jobKey>/<folder>.
        $stageDir = rtrim($this->stagingBaseDir, '/') . '/' . $this->safeName($jobKey) . '/' . $folder;
        $this->ensureDir($stageDir);
        $sourceBase = rtrim($sourcePath, '/');
        foreach (self::filesMatching($sourcePath, static fn (): bool => true) as $src) {
            if ($src === $sourcePath) {
                // Single-file payload: stage it at the staged-folder root.
                $target = $stageDir . '/' . $this->safeName(basename($src));
            } else {
                // Sanitize each path segment individually so no segment can
                // escape the staging dir, then rejoin to preserve the tree.
                $rel = substr($src, \strlen($sourceBase) + 1);
                $segments = array_map($this->safeName(...), explode('/', $rel));
                $target = $stageDir . '/' . implode('/', $segments);
                $this->ensureDir(\dirname($target));
            }
            if (!@copy($src, $target)) {
                $this->removeTree(\dirname($stageDir));
                throw new \RuntimeException("Failed to stage file: {$src}");
            }
        }

        $this->normalizeCover($stageDir);

        if ($beforeFinalize !== null) {
            try {
                $beforeFinalize($stageDir);
            } catch (\Throwable $e) {
                $this->removeTree(\dirname($stageDir));
                throw $e;
            }
        }

        // -- Final: move the staged folder into the destination (collision-safe).
        $this->ensureDir($destDir);
        if (!is_writable($destDir)) {
            $this->removeTree(\dirname($stageDir));
            throw new \RuntimeException("Audiobook destination is not writable: {$destDir}");
        }
        $finalDir = $this->uniqueDir($destDir, $folder);

        if (!@rename($stageDir, $finalDir)) {
            // Cross-device: recursively copy then remove the staged tree.
            $this->copyTree($stageDir, $finalDir);
            $this->removeTree($stageDir);
        }
        // Clean up the now-empty per-job staging parent.
        @rmdir(\dirname($stageDir));

        return $finalDir;
    }

    /**
     * Ensure the staged root carries a cover file Grimmory's folder-cover fallback
     * recognizes. If one is already there, leave everything alone; otherwise copy
     * the largest image in the tree (original kept) to the root as cover.<ext>.
     * No images at all is fine — nothing to normalize.
     */
    private function normalizeCover(string $stageDir): void
    {
        foreach (scandir($stageDir) ?: [] as $entry) {
            if (!is_file($stageDir . '/' . $entry)) {
                continue;
            }
            $base = strtolower(pathinfo($entry, PATHINFO_FILENAME));
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (\in_array($base, self::COVER_BASENAMES, true) && \in_array($ext, self::COVER_EXTENSIONS, true)) {
                return;
            }
        }

        $images = self::filesMatching(
            $stageDir,
            static fn (string $p): bool => \in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), self::COVER_EXTENSIONS, true),
        );
        if ($images === []) {
            return;
        }

        $largest = null;
        $largestSize = -1;
        foreach ($images as $image) {
            $size = @filesize($image);
            if ($size !== false && $size > $largestSize) {
                $largest = $image;
                $largestSize = $size;
            }
        }
        if ($largest === null) {
            return;
        }

        $ext = strtolower(pathinfo($largest, PATHINFO_EXTENSION));
        if (!@copy($largest, $stageDir . '/cover.' . $ext)) {
            throw new \RuntimeException("Failed to normalize cover image: {$largest}");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Directory could not be created: {$dir}");
        }
    }

    private function uniqueDir(string $parent, string $name): string
    {
        $candidate = $parent . '/' . $name;
        $n = 1;
        while (file_exists($candidate)) {
            $candidate = $parent . '/' . $name . ' (' . $n . ')';
            ++$n;
        }

        return $candidate;
    }

    private function copyTree(string $src, string $dest): void
    {
        $this->ensureDir($dest);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $targetPath = $dest . '/' . $rel;
            if ($item->isDir()) {
                $this->ensureDir($targetPath);
            } elseif (!@copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException("Failed to copy into destination: {$targetPath}");
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#', '', $name) ?? '';
        $name = trim(preg_replace('/\s{2,}/', ' ', $name) ?? '', " \t.-_");

        return $name === '' ? 'audiobook' : $name;
    }
}
