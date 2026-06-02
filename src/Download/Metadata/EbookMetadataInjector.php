<?php

declare(strict_types=1);

namespace App\Download\Metadata;

use App\Entity\Book;
use App\Service\AppSettingsProvider;
use App\Service\BookCoverProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort post-download step: when the operator has the "overwrite metadata"
 * toggle on (Settings → General, default on), rewrite a freshly downloaded EPUB's
 * embedded metadata with Spine Scout's stored values before it is moved into the
 * library. EPUB-only for now; other formats pass through untouched.
 *
 * Never throws — a metadata hiccup must not lose an otherwise-good download — so
 * the caller can always proceed to move the file.
 */
final class EbookMetadataInjector
{
    public function __construct(
        private readonly AppSettingsProvider $settings,
        private readonly EpubMetadataWriter $epubWriter,
        private readonly BookCoverProvider $coverProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Rewrite $filePath's metadata in place from $book. $format is the download
     * job's detected format (e.g. "epub"); the file extension is used as a fallback.
     *
     * @return bool true when metadata was actually rewritten, false when skipped or failed
     */
    public function inject(string $filePath, Book $book, ?string $format): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if (!$this->isEpub($filePath, $format)) {
            $this->logger->info('Metadata injection skipped: unsupported format', [
                'path' => $filePath,
                'format' => $format,
            ]);
            return false;
        }

        try {
            $cover = $this->coverProvider->originalCoverForBook($book);
            $this->epubWriter->write($filePath, $book, $cover);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Metadata injection failed; shipping file as-is', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function isEnabled(): bool
    {
        return $this->settings->isMetadataOverwriteEnabled();
    }

    private function isEpub(string $filePath, ?string $format): bool
    {
        if ($format !== null && strtolower(trim($format)) === 'epub') {
            return true;
        }
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'epub';
    }
}
