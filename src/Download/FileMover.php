<?php

declare(strict_types=1);

namespace App\Download;

/**
 * Moves a completed download out of the staging dir into the operator's output
 * folder, atomically when possible (rename within the same filesystem) with a
 * copy+unlink fallback across mount boundaries. Collision-safe: an existing
 * target name gets a " (1)", " (2)", … suffix.
 *
 * Throws on any failure so the caller can mark the job errored with the reason.
 */
final class FileMover
{
    public function move(string $stagedPath, string $destDir, string $filename): string
    {
        if (!is_file($stagedPath)) {
            throw new \RuntimeException("Staged file no longer exists: {$stagedPath}");
        }
        $destDir = rtrim(trim($destDir), '/');
        if ($destDir === '') {
            throw new \RuntimeException('No output directory configured.');
        }
        if (!is_dir($destDir) && !@mkdir($destDir, 0o775, true) && !is_dir($destDir)) {
            throw new \RuntimeException("Output directory could not be created: {$destDir}");
        }
        if (!is_writable($destDir)) {
            throw new \RuntimeException("Output directory is not writable: {$destDir}");
        }

        $target = $this->uniqueTarget($destDir, $filename);

        if (@rename($stagedPath, $target)) {
            return $target;
        }
        // Cross-device move: copy then remove the source.
        if (!@copy($stagedPath, $target)) {
            throw new \RuntimeException("Failed to move file into output directory: {$target}");
        }
        @unlink($stagedPath);

        return $target;
    }

    private function uniqueTarget(string $destDir, string $filename): string
    {
        $filename = $this->safeBasename($filename);
        $dot = strrpos($filename, '.');
        if ($dot !== false && $dot > 0) {
            $stem = substr($filename, 0, $dot);
            $ext = substr($filename, $dot); // includes the dot
        } else {
            $stem = $filename;
            $ext = '';
        }

        $candidate = $destDir . '/' . $stem . $ext;
        $n = 1;
        while (file_exists($candidate)) {
            $candidate = $destDir . '/' . $stem . ' (' . $n . ')' . $ext;
            ++$n;
        }

        return $candidate;
    }

    private function safeBasename(string $filename): string
    {
        $filename = basename($filename);

        return $filename === '' ? 'download' : $filename;
    }
}
