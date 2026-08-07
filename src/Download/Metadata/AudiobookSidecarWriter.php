<?php

declare(strict_types=1);

namespace App\Download\Metadata;

use App\Entity\Book;
use App\Service\BookCoverProvider;
use App\Support\AudioFormat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes a Grimmory-compatible JSON metadata sidecar (and, best-effort, a cover
 * image) for a finished audiobook, so the library holds a portable, importable copy
 * of Spine Scout's stored metadata.
 *
 * Grimmory resolves a book's sidecar as "<bookPath parent>/<basename>.metadata.json"
 * and "<basename>.cover.jpg", where basename is the book path's filename truncated at
 * its LAST dot (if any). What the "book path" is depends on the audiobook's shape, so
 * sidecar placement must match it or the sidecar is never found:
 *
 *  - FOLDER-BASED audiobook (2+ audio files in the folder): the book path is the
 *    folder itself, so the sidecar goes BESIDE the folder, named after the folder.
 *    Because of the dot-truncation quirk, a folder named "Book Vol. 1" is matched by
 *    "Book Vol.metadata.json" (name up to the last dot) — we mimic that exactly.
 *  - SINGLE-FILE audiobook (exactly 1 audio file, e.g. one .m4b): the book path is
 *    the audio FILE, so the sidecar goes INSIDE the folder, next to the file, named
 *    "<audio filename minus extension>.metadata.json" / ".cover.jpg".
 *
 * {@see writeForAlbum()} counts the album's audio files (via {@see AudioFormat};
 * companion files like jpg/nfo/cue don't count) and picks the right placement.
 * The JSON is the {version, generatedAt, generatedBy, metadata{...}} envelope; only
 * non-null metadata fields are emitted. The cover is always written as ".cover.jpg"
 * (the only name Grimmory reads), transcoding to JPEG when the provider hands back
 * another format.
 *
 * Never throws — a sidecar hiccup must not lose an otherwise-good download — so the
 * caller can always treat the import as successful.
 */
final class AudiobookSidecarWriter
{
    private const VERSION      = '1.0';
    private const GENERATED_BY = 'spinescout';

    public function __construct(
        private readonly BookCoverProvider $coverProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Inspect $albumDir's audio files and write the sidecar where Grimmory will look
     * for it: next to the single audio file (named after the file) for single-file
     * audiobooks, or beside $albumDir (named after the folder, dot-truncated) for
     * folder-based ones. With no audio files at all, nothing is written.
     */
    public function writeForAlbum(string $albumDir, Book $book): void
    {
        $albumDir = rtrim($albumDir, '/');

        try {
            $audioFiles = $this->audioFiles($albumDir);
        } catch (\Throwable $e) {
            $this->logger->warning('Audiobook sidecar skipped: album folder could not be scanned', [
                'dir'   => $albumDir,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($audioFiles === []) {
            $this->logger->warning('Audiobook sidecar skipped: album folder contains no audio files', ['dir' => $albumDir]);

            return;
        }

        if (\count($audioFiles) === 1) {
            // Single-file audiobook: Grimmory's book path is the audio file itself,
            // so the sidecar lives next to the file (which may sit in a subfolder).
            $file = $audioFiles[0];
            $this->writeSidecar(\dirname($file), $this->truncateAtLastDot(basename($file)), $book);

            return;
        }

        // Folder-based audiobook: the book path is the folder, so the sidecar lives
        // beside it, named after the folder up to its last dot.
        $this->writeSidecar(\dirname($albumDir), $this->truncateAtLastDot(basename($albumDir)), $book);
    }

    /**
     * Write "<baseName>.metadata.json" (and a best-effort "<baseName>.cover.jpg")
     * into $folder, overwriting any existing sidecar. Callers that already know the
     * placement pass it directly; prefer {@see writeForAlbum()} which derives the
     * Grimmory-correct placement from the album's audio files.
     */
    public function write(string $folder, string $baseName, Book $book): void
    {
        $this->writeSidecar(rtrim($folder, '/'), $baseName, $book);
    }

    /** The shared sidecar emitter behind both public entry points. Never throws. */
    private function writeSidecar(string $folder, string $baseName, Book $book): void
    {
        $base = $this->safeBase($baseName);

        $json = json_encode($this->envelope($book), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logger->warning('Audiobook sidecar skipped: metadata could not be encoded', ['folder' => $folder]);

            return;
        }

        $jsonPath = $folder . '/' . $base . '.metadata.json';
        if (@file_put_contents($jsonPath, $json . "\n") === false) {
            $this->logger->warning('Audiobook sidecar could not be written', ['path' => $jsonPath]);

            return;
        }

        $this->writeCover($folder, $base, $book);
    }

    /**
     * All audio files under $albumDir (recursive), judged by extension via
     * {@see AudioFormat::isAudio()} — mirrors how Grimmory decides whether a folder
     * groups into one folder-based book (2+ audio files) or is a single-file book.
     *
     * @return list<string> absolute paths
     */
    private function audioFiles(string $albumDir): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($albumDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && AudioFormat::isAudio($file->getExtension())) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Grimmory's basename rule: the name truncated at its LAST dot when one exists
     * past position 0 (so dot-leading names and dotless names pass through whole).
     * For a file this strips the extension; for a folder like "Book Vol. 1" it
     * yields "Book Vol".
     */
    private function truncateAtLastDot(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos !== false && $pos > 0) {
            return substr($name, 0, $pos);
        }

        return $name;
    }

    /**
     * @return array{version: string, generatedAt: string, generatedBy: string, metadata: array<string, mixed>}
     */
    private function envelope(Book $book): array
    {
        return [
            'version'     => self::VERSION,
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'generatedBy' => self::GENERATED_BY,
            'metadata'    => $this->metadata($book),
        ];
    }

    /**
     * The metadata block, carrying only the fields Spine Scout has values for
     * (Grimmory's sidecar omits nulls).
     *
     * @return array<string, mixed>
     */
    private function metadata(Book $book): array
    {
        $out = ['title' => $book->getTitle()];

        $authors = $this->authors($book->getAuthor());
        if ($authors !== []) {
            $out['authors'] = $authors;
        }
        $this->put($out, 'publisher', $book->getPublisher());
        $this->put($out, 'publishedDate', $book->getPublishedDate());
        $this->put($out, 'description', $book->getDescription());

        [$isbn13, $isbn10] = $this->isbns($book);
        $this->put($out, 'isbn13', $isbn13);
        $this->put($out, 'isbn10', $isbn10);

        $categories = array_values(array_filter(array_map('trim', $book->getGenres()), static fn (string $g): bool => $g !== ''));
        if ($categories !== []) {
            $out['categories'] = $categories;
        }
        $this->put($out, 'language', $book->getLanguage());

        $this->put($out, 'seriesName', $book->getSeries());
        $this->put($out, 'seriesNumber', $book->getSeriesIndex());
        if ($book->getSeriesTotal() !== null) {
            $out['seriesTotal'] = $book->getSeriesTotal();
        }

        // Audiobook-specific.
        $this->put($out, 'narrator', $book->getNarrator());

        return $out;
    }

    /**
     * Spine Scout stores authors comma-joined in a single string; the sidecar wants
     * a list.
     *
     * @return list<string>
     */
    private function authors(?string $author): array
    {
        if ($author === null || trim($author) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $author)),
            static fn (string $a): bool => $a !== '',
        ));
    }

    /**
     * Pick a canonical ISBN-13 and ISBN-10 from the book's normalized ISBN and the
     * full editions list, classifying each by its digit length.
     *
     * @return array{0: ?string, 1: ?string} [isbn13, isbn10]
     */
    private function isbns(Book $book): array
    {
        $isbn13 = null;
        $isbn10 = null;
        $candidates = $book->getIsbn() !== null ? [$book->getIsbn()] : [];
        foreach ($book->getIsbns() as $i) {
            $candidates[] = $i;
        }

        foreach ($candidates as $raw) {
            $norm = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $raw) ?? '');
            if ($isbn13 === null && \strlen($norm) === 13) {
                $isbn13 = $norm;
            } elseif ($isbn10 === null && \strlen($norm) === 10) {
                $isbn10 = $norm;
            }
        }

        return [$isbn13, $isbn10];
    }

    /**
     * Download the cover and save it as "<base>.cover.jpg"; best-effort, never fatal.
     * Grimmory only reads that exact name, so non-JPEG provider bytes are transcoded
     * to JPEG with GD rather than written under a lying extension.
     */
    private function writeCover(string $folder, string $base, Book $book): void
    {
        try {
            $cover = $this->coverProvider->originalCoverForBook($book);
        } catch (\Throwable $e) {
            $this->logger->info('Audiobook cover fetch failed; sidecar JSON written without it', ['error' => $e->getMessage()]);

            return;
        }
        if ($cover === null) {
            return;
        }

        [$bytes, $mimeType] = $cover;
        if (strtolower($mimeType) !== 'image/jpeg') {
            $bytes = $this->transcodeToJpeg($bytes);
            if ($bytes === null) {
                $this->logger->info('Audiobook cover skipped: non-JPEG cover could not be transcoded', [
                    'mimeType' => $mimeType,
                ]);

                return;
            }
        }

        $coverPath = $folder . '/' . $base . '.cover.jpg';
        if (@file_put_contents($coverPath, $bytes) === false) {
            $this->logger->info('Audiobook cover could not be written', ['path' => $coverPath]);
        }
    }

    /** Re-encode arbitrary raster bytes as JPEG via GD, or null when GD can't. */
    private function transcodeToJpeg(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        ob_start();
        $ok = @imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return ($ok && \is_string($jpeg) && $jpeg !== '') ? $jpeg : null;
    }

    /** @param array<string, mixed> $out */
    private function put(array &$out, string $key, ?string $value): void
    {
        if ($value !== null && trim($value) !== '') {
            $out[$key] = $value;
        }
    }

    /** Strip path separators / illegal chars so the base can't escape the folder. */
    private function safeBase(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'audiobook' : $name;
    }
}
