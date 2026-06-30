<?php

declare(strict_types=1);

namespace App\Download\Metadata;

use App\Entity\Book;
use App\Service\BookCoverProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes a Grimmory-compatible JSON metadata sidecar (and, best-effort, a cover
 * image) into a finished audiobook's folder, so the library holds a portable,
 * importable copy of Spine Scout's stored metadata next to the audio files.
 *
 * The on-disk shape matches Grimmory's sidecar contract: for a folder named
 * "<base>", we write "<base>.metadata.json" and "<base>.cover.jpg". The JSON is
 * the {version, generatedAt, generatedBy, metadata{...}} envelope; only non-null
 * metadata fields are emitted.
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
     * Write "<baseName>.metadata.json" (and a best-effort "<baseName>.cover.jpg")
     * into $folder, overwriting any existing sidecar. $baseName is the audiobook
     * folder's own name so the sidecar sits at the album level beside the audio.
     */
    public function write(string $folder, string $baseName, Book $book): void
    {
        $folder = rtrim($folder, '/');
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

    /** Download the cover and save it as "<base>.cover.jpg"; best-effort, never fatal. */
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

        $coverPath = $folder . '/' . $base . '.cover.jpg';
        if (@file_put_contents($coverPath, $cover[0]) === false) {
            $this->logger->info('Audiobook cover could not be written', ['path' => $coverPath]);
        }
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
