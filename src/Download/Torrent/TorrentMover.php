<?php

declare(strict_types=1);

namespace App\Download\Torrent;

use App\Support\AudioFormat;

/**
 * Moves a finished audiobook torrent out of qBittorrent's completed folder into
 * the library, in the two stages the operator flow describes: copy the audio
 * file(s) into a Spine Scout staging dir, then move that folder into the final
 * destination. We copy (never move) from qBittorrent's dir so the torrent keeps
 * seeding.
 *
 * Audiobooks are often a folder of .mp3 or a single .m4b, so this works on a tree
 * — unlike the single-file FileMover used for ebooks. Only audio files are taken;
 * cover art / nfo / sample junk is left behind. Collision-safe and resilient to
 * cross-device moves. Throws on any failure so the caller marks the job errored.
 */
final class TorrentMover
{
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
     * Stage the audio files from $sourcePath, then move them into $destDir under a
     * folder named $folderName. Returns the final folder path.
     *
     * @param string $jobKey A unique-per-job token so concurrent moves don't collide in staging.
     */
    public function move(string $sourcePath, string $destDir, string $folderName, string $jobKey): string
    {
        $audioFiles = self::audioFiles($sourcePath);
        if ($audioFiles === []) {
            throw new \RuntimeException("No audio files found in torrent payload: {$sourcePath}");
        }

        $destDir = rtrim(trim($destDir), '/');
        if ($destDir === '') {
            throw new \RuntimeException('No audiobook destination directory configured.');
        }

        $folder = $this->safeName($folderName);

        // -- Stage: copy the audio files into var/downloads/<staging>/<jobKey>/<folder>.
        $stageDir = rtrim($this->stagingBaseDir, '/') . '/' . $this->safeName($jobKey) . '/' . $folder;
        $this->ensureDir($stageDir);
        foreach ($audioFiles as $src) {
            $target = $stageDir . '/' . $this->safeName(basename($src));
            if (!@copy($src, $target)) {
                $this->removeTree(\dirname($stageDir));
                throw new \RuntimeException("Failed to stage audio file: {$src}");
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
