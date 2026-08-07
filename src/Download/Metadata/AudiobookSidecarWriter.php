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
 * non-null metadata fields are emitted, shaped exactly to Grimmory's sidecar DTOs
 * (nested series object, float series number, strict Y-m-d publishedDate — a type
 * mismatch makes Grimmory reject the whole file). The cover is written twice from
 * one fetch: as ".cover.jpg" (the only sidecar name Grimmory reads) and as the
 * album's root "cover.jpg" — Grimmory's sidecar import does NOT apply the cover
 * file, so the scan-time cover.jpg is what actually shows; the release's own
 * root-level artwork is removed so it can't win. Transcodes to JPEG when the
 * provider hands back another format.
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
     *
     * The book's cover is written twice from a single fetch: as the sidecar
     * "<base>.cover.jpg" (applied on import) and as the album's scan-time
     * "cover.jpg", replacing whatever artwork the release shipped with — so the
     * library shows Spine Scout's cover immediately, not the torrent's.
     */
    public function writeForAlbum(string $albumDir, Book $book): void
    {
        $placement = $this->resolvePlacement($albumDir);
        if ($placement === null) {
            return;
        }

        $coverJpeg = $this->coverJpegBytes($book);
        $this->writeSidecar($placement['folder'], $placement['base'], $book, $coverJpeg);
        $this->replaceAlbumCover($placement['coverDir'], $coverJpeg);
    }

    /**
     * Write only the scan-time album cover ("cover.jpg" next to the audio),
     * replacing the release's own artwork. For operators whose library server
     * isn't Grimmory (sidecars disabled) — every scanner reads cover.jpg.
     */
    public function writeAlbumCover(string $albumDir, Book $book): void
    {
        $placement = $this->resolvePlacement($albumDir);
        if ($placement === null) {
            return;
        }

        $this->replaceAlbumCover($placement['coverDir'], $this->coverJpegBytes($book));
    }

    /**
     * Grimmory-correct sidecar placement for the album, plus the folder whose
     * root-level cover.jpg the scanner reads. Null (logged) when the folder can't
     * be scanned or holds no audio files.
     *
     * @return ?array{folder: string, base: string, coverDir: string}
     */
    private function resolvePlacement(string $albumDir): ?array
    {
        $albumDir = rtrim($albumDir, '/');

        try {
            $audioFiles = $this->audioFiles($albumDir);
        } catch (\Throwable $e) {
            $this->logger->warning('Audiobook sidecar skipped: album folder could not be scanned', [
                'dir'   => $albumDir,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($audioFiles === []) {
            $this->logger->warning('Audiobook sidecar skipped: album folder contains no audio files', ['dir' => $albumDir]);

            return null;
        }

        if (\count($audioFiles) === 1) {
            // Single-file audiobook: Grimmory's book path is the audio file itself,
            // so the sidecar lives next to the file (which may sit in a subfolder).
            $file = $audioFiles[0];

            return [
                'folder'   => \dirname($file),
                'base'     => $this->truncateAtLastDot(basename($file)),
                'coverDir' => \dirname($file),
            ];
        }

        // Folder-based audiobook: the book path is the folder, so the sidecar lives
        // beside it, named after the folder up to its last dot.
        return [
            'folder'   => \dirname($albumDir),
            'base'     => $this->truncateAtLastDot(basename($albumDir)),
            'coverDir' => $albumDir,
        ];
    }

    /**
     * Write "<baseName>.metadata.json" (and a best-effort "<baseName>.cover.jpg")
     * into $folder, overwriting any existing sidecar. Callers that already know the
     * placement pass it directly; prefer {@see writeForAlbum()} which derives the
     * Grimmory-correct placement from the album's audio files.
     */
    public function write(string $folder, string $baseName, Book $book): void
    {
        $this->writeSidecar(rtrim($folder, '/'), $baseName, $book, $this->coverJpegBytes($book));
    }

    /** The shared sidecar emitter behind both public entry points. Never throws. */
    private function writeSidecar(string $folder, string $baseName, Book $book, ?string $coverJpeg): void
    {
        $base = $this->safeBase($baseName);

        $json = json_encode($this->envelope($book), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            $this->logger->warning('Audiobook sidecar skipped: metadata could not be encoded', ['folder' => $folder]);

            return;
        }

        $jsonPath = $folder . '/' . $base . '.metadata.json';
        if (@file_put_contents($jsonPath, $json . "\n") === false) {
            $this->logger->warning('Audiobook sidecar could not be written', ['path' => $jsonPath]);

            return;
        }

        if ($coverJpeg !== null) {
            $coverPath = $folder . '/' . $base . '.cover.jpg';
            if (@file_put_contents($coverPath, $coverJpeg) === false) {
                $this->logger->info('Audiobook cover could not be written', ['path' => $coverPath]);
            }
        }
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
     * The metadata block, carrying only the fields Spine Scout has values for.
     * Field names and types follow Grimmory's SidecarBookMetadata DTO exactly:
     * series is a NESTED {name, number, total} object (flat seriesName/… keys are
     * silently ignored), series.number is a float, and publishedDate must be a
     * strict ISO "Y-m-d" LocalDate. Unknown keys are dropped harmlessly, but a
     * TYPE mismatch makes Grimmory reject the whole sidecar for that book — so
     * every value here is normalized to the expected JSON type or omitted.
     *
     * @return array<string, mixed>
     */
    private function metadata(Book $book): array
    {
        // The formatted display title, so Grimmory's "recently added" reads
        // exactly like Spine Scout's home carousel.
        $out = ['title' => $book->displayTitle()];

        $authors = $this->authors($book->getAuthor());
        if ($authors !== []) {
            $out['authors'] = $authors;
        }
        $this->put($out, 'publisher', $book->getPublisher());
        $this->put($out, 'publishedDate', $this->isoDate($book->getPublishedDate()));
        $this->put($out, 'description', $book->getDescription());

        [$isbn13, $isbn10] = $this->isbns($book);
        $this->put($out, 'isbn13', $isbn13);
        $this->put($out, 'isbn10', $isbn10);

        $categories = array_values(array_filter(array_map('trim', $book->getGenres()), static fn (string $g): bool => $g !== ''));
        if ($categories !== []) {
            $out['categories'] = $categories;
        }
        $this->put($out, 'language', $book->getLanguage());

        $series = $this->series($book);
        if ($series !== []) {
            $out['series'] = $series;
        }

        $identifiers = $this->identifiers($book);
        if ($identifiers !== []) {
            $out['identifiers'] = $identifiers;
        }

        // Audiobook-specific.
        $this->put($out, 'narrator', $book->getNarrator());

        return $out;
    }

    /**
     * Grimmory's nested series object: name (string), number (float — decimals
     * like "1.5" are legal), total (int). A non-numeric series index is omitted
     * rather than sent as a string, which would fail the Float field and void
     * the entire sidecar.
     *
     * @return array<string, mixed>
     */
    private function series(Book $book): array
    {
        $series = [];
        $name = $book->getSeries();
        if ($name !== null && trim($name) !== '') {
            $series['name'] = trim($name);
        }
        $index = trim((string) $book->getSeriesIndex());
        if ($index !== '' && is_numeric($index)) {
            $series['number'] = (float) $index;
        }
        if ($book->getSeriesTotal() !== null) {
            $series['total'] = $book->getSeriesTotal();
        }

        return $series;
    }

    /**
     * Provider identifiers (all strings in Grimmory's schema). We only ever hold
     * the Hardcover slug the book was requested from.
     *
     * @return array<string, string>
     */
    private function identifiers(Book $book): array
    {
        if ($book->getSource() === Book::SOURCE_HARDCOVER && $book->getExternalId() !== '') {
            return ['hardcoverId' => $book->getExternalId()];
        }

        return [];
    }

    /**
     * Normalize Spine Scout's free-form published date ("2024", "May 2024",
     * "2024-05-21") to the strict "Y-m-d" LocalDate Grimmory requires — any other
     * shape is a deserialization error that voids the whole sidecar. Year and
     * year-month values are anchored to the first day; unparseable ones are
     * dropped.
     */
    private function isoDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }
        if (preg_match('/^\d{4}$/', $raw) === 1) {
            return $raw . '-01-01';
        }
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m) === 1) {
            return sprintf('%s-%02d-01', $m[1], (int) $m[2]);
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
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
     * The book's cover as JPEG bytes, or null when unavailable; best-effort, never
     * fatal. Fetched once and reused for both the "<base>.cover.jpg" sidecar and
     * the album's scan-time "cover.jpg". Non-JPEG provider bytes are transcoded to
     * JPEG with GD rather than written under a lying extension. Public so the
     * finalizer can also embed the same artwork into the audio files via tone.
     */
    public function coverJpegBytes(Book $book): ?string
    {
        try {
            $cover = $this->coverProvider->originalCoverForBook($book);
        } catch (\Throwable $e) {
            $this->logger->info('Audiobook cover fetch failed; proceeding without it', ['error' => $e->getMessage()]);

            return null;
        }
        if ($cover === null) {
            $this->logger->info('Audiobook cover unavailable: book has no stored cover source', ['book' => $book->getId()]);

            return null;
        }

        [$bytes, $mimeType] = $cover;
        if (strtolower($mimeType) !== 'image/jpeg') {
            $bytes = $this->transcodeToJpeg($bytes);
            if ($bytes === null) {
                $this->logger->info('Audiobook cover skipped: non-JPEG cover could not be transcoded', [
                    'mimeType' => $mimeType,
                ]);

                return null;
            }
        }

        return $bytes;
    }

    /**
     * Make Spine Scout's cover THE scan-time cover: write it as "cover.jpg" in the
     * folder the scanner reads, deleting the release's competing root-level
     * artwork (cover/folder/image.* variants) so no other file can win. No-op when
     * we have no cover — the release's own art is better than none.
     */
    private function replaceAlbumCover(string $dir, ?string $coverJpeg): void
    {
        if ($coverJpeg === null) {
            return;
        }

        foreach (@scandir($dir) ?: [] as $entry) {
            $base = strtolower(pathinfo($entry, PATHINFO_FILENAME));
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($entry !== 'cover.jpg'
                && \in_array($base, ['cover', 'folder', 'image'], true)
                && \in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)
                && is_file($dir . '/' . $entry)
            ) {
                @unlink($dir . '/' . $entry);
            }
        }

        if (@file_put_contents($dir . '/cover.jpg', $coverJpeg) === false) {
            $this->logger->info('Album cover could not be written', ['path' => $dir . '/cover.jpg']);
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
