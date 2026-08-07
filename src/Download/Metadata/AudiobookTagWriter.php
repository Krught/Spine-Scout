<?php

declare(strict_types=1);

namespace App\Download\Metadata;

use App\Entity\Book;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Fills in MISSING embedded tags on downloaded audiobook files using the `tone`
 * CLI (https://github.com/sandreas/tone), so the downstream Grimmory scanner —
 * which reads embedded tags — gets complete data even from sparsely-tagged
 * releases.
 *
 * Hard constraints, locked by policy:
 *  - Tag-level edits ONLY (tone rewrites tag atoms/frames, never remuxes audio).
 *  - Existing TEXT tag values are NEVER overwritten — only empty/absent fields
 *    whose value the {@see Book} entity knows are written. The single exception
 *    is {@see embedCover()}, which deliberately replaces embedded artwork.
 *  - Every write is wrapped in backup-verify-swap: the file is copied aside,
 *    tagged, re-dumped, and verified (dump parses, duration within
 *    {@see self::DURATION_TOLERANCE_MS}, chapter count unchanged); any failure
 *    restores the backup byte-for-byte.
 *  - A tagging failure must NEVER fail the import: {@see fillMissingTags()}
 *    never throws.
 *
 * Field mapping mirrors what Grimmory's scanner reads (jaudiotagger FieldKeys):
 * ARTIST/ALBUM_ARTIST→authors, ALBUM→title, COMPOSER→narrator,
 * COMMENT→description, RECORD_LABEL→publisher, GENRE→genres, GROUPING→series
 * name (©grp on mp4, TIT1 on id3, GROUPING vorbis comment — tone's
 * --meta-group), TRACK→series number. tone 0.2.5 has no language flag, so
 * LANGUAGE is left to the metadata sidecar.
 *
 * Only mp3/m4a/m4b/opus files are touched — the formats Grimmory tag-reads.
 */
final class AudiobookTagWriter
{
    /** Extensions (lowercased) eligible for tagging; everything else is left alone. */
    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'm4b', 'opus'];

    /** Post-write duration drift beyond this (tone reports milliseconds) fails verification. */
    private const DURATION_TOLERANCE_MS = 2000.0;

    private const VERSION_TIMEOUT_SECONDS = 10.0;
    private const TONE_TIMEOUT_SECONDS    = 120.0;

    private ?bool $available = null;
    private bool $unavailabilityLogged = false;

    public function __construct(
        private readonly string $toneBinary,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** Whether the tone binary runs on this host (cached; dev hosts usually lack it). */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $process = new Process([$this->toneBinary, '--version'], timeout: self::VERSION_TIMEOUT_SECONDS);
            $process->run();
            $this->available = $process->isSuccessful();
        } catch (\Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /**
     * Fill missing tags on every eligible audio file under $dir with values the
     * Book knows. Never throws; per-file failures restore the original bytes and
     * are logged, and with tone unavailable the whole call is a logged no-op.
     */
    public function fillMissingTags(string $dir, Book $book): void
    {
        try {
            if (!$this->isAvailable()) {
                if (!$this->unavailabilityLogged) {
                    $this->unavailabilityLogged = true;
                    $this->logger->info('Audiobook tag fill skipped: tone binary is not available', [
                        'binary' => $this->toneBinary,
                    ]);
                }

                return;
            }

            $files = $this->audioFiles(rtrim($dir, '/'));
            if ($files === []) {
                return;
            }

            // On multi-file audiobooks track numbers order playback — never touch
            // them. Only a single-file audiobook may take seriesIndex as TRACK.
            $singleFile = \count($files) === 1;

            foreach ($files as $file) {
                $this->fillFile($file, $book, $singleFile);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Audiobook tag fill aborted', [
                'dir'   => $dir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Embed $coverFile (a JPEG on disk) as the artwork of every eligible audio
     * file under $dir, REPLACING any existing embedded picture. This is the one
     * deliberate exception to the never-overwrite rule: Grimmory's scanner
     * prefers embedded art over the folder's cover.jpg, so the release's own
     * artwork would otherwise always win over the cover the user requested.
     * Same backup-verify-swap safety as the tag fill; never throws.
     */
    public function embedCover(string $dir, string $coverFile): void
    {
        try {
            if (!$this->isAvailable()) {
                if (!$this->unavailabilityLogged) {
                    $this->unavailabilityLogged = true;
                    $this->logger->info('Audiobook cover embed skipped: tone binary is not available', [
                        'binary' => $this->toneBinary,
                    ]);
                }

                return;
            }

            foreach ($this->audioFiles(rtrim($dir, '/')) as $file) {
                $before = $this->dump($file);
                if ($before === null) {
                    $this->logger->warning('Audiobook cover embed skipped a file: tone dump was unparseable', ['file' => $file]);

                    continue;
                }
                $this->tagFile($file, ['--meta-cover-file', $coverFile], $before, 'Audiobook cover embedded');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Audiobook cover embed aborted', [
                'dir'   => $dir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Dump, diff against the Book, and (when anything is missing) backup-tag-verify one file. */
    private function fillFile(string $file, Book $book, bool $singleFile): void
    {
        $before = $this->dump($file);
        if ($before === null) {
            $this->logger->warning('Audiobook tag fill skipped a file: tone dump was unparseable', ['file' => $file]);

            return;
        }

        $flags = $this->missingTagFlags($before['meta'], $book, $singleFile);
        if ($flags === []) {
            return; // Nothing missing that we can supply — no write at all.
        }

        $this->tagFile($file, $flags, $before, 'Audiobook tags filled');
    }

    /**
     * Backup-tag-verify one file: copy aside, run `tone tag` with $flags, re-dump
     * and check duration/chapters survived, restoring the backup on any failure.
     *
     * @param list<string> $flags
     * @param array{meta: array<string, mixed>, duration: float, chapterCount: int} $before
     */
    private function tagFile(string $file, array $flags, array $before, string $successMessage): void
    {
        $backup = $file . '.spinescout.bak';
        if (!@copy($file, $backup)) {
            $this->logger->warning('Audiobook tag fill skipped a file: backup copy failed', ['file' => $file]);

            return;
        }

        try {
            $tag = new Process(
                [$this->toneBinary, 'tag', $file, ...$flags, '--assume-yes'],
                timeout: self::TONE_TIMEOUT_SECONDS,
            );
            $tag->run();

            if (!$tag->isSuccessful()) {
                $this->restore($backup, $file, 'tone tag exited non-zero', ['exitCode' => $tag->getExitCode()]);

                return;
            }

            $after = $this->dump($file);
            if ($after === null) {
                $this->restore($backup, $file, 'post-write dump was unparseable');

                return;
            }
            if (abs($after['duration'] - $before['duration']) > self::DURATION_TOLERANCE_MS) {
                $this->restore($backup, $file, 'post-write duration drifted', [
                    'before' => $before['duration'],
                    'after'  => $after['duration'],
                ]);

                return;
            }
            if ($after['chapterCount'] !== $before['chapterCount']) {
                $this->restore($backup, $file, 'post-write chapter count changed', [
                    'before' => $before['chapterCount'],
                    'after'  => $after['chapterCount'],
                ]);

                return;
            }

            $this->logger->info($successMessage, ['file' => $file, 'flags' => $flags]);
        } catch (\Throwable $e) {
            $this->restore($backup, $file, 'tagging threw', ['error' => $e->getMessage()]);
        } finally {
            // The backup must never leak into the library, verified or not.
            if (file_exists($backup) && !@unlink($backup)) {
                $this->logger->warning('Audiobook tag backup could not be removed', ['backup' => $backup]);
            }
        }
    }

    /**
     * The `tone tag` flags for fields empty/absent in the file's meta AND known
     * on the Book. Flag names and dump keys verified against tone 0.2.5:
     * --meta-album/-artist/-album-artist/-composer/-group/-publisher/-genre/
     * -description/-comment/-track-number; --meta-group writes the mp4 ©grp /
     * id3 TIT1 "grouping" field Grimmory maps to series name.
     *
     * @param array<string, mixed> $meta the dump's "meta" object
     *
     * @return list<string>
     */
    private function missingTagFlags(array $meta, Book $book, bool $singleFile): array
    {
        $flags = [];
        $put = function (string $flag, string $metaKey, ?string $value) use (&$flags, $meta): void {
            if ($value !== null && trim($value) !== '' && $this->isEmptyMeta($meta[$metaKey] ?? null)) {
                $flags[] = $flag;
                $flags[] = trim($value);
            }
        };

        $put('--meta-album', 'album', $book->displayTitle());
        $put('--meta-artist', 'artist', $book->getAuthor());
        $put('--meta-album-artist', 'albumArtist', $book->getAuthor());
        $put('--meta-composer', 'composer', $book->getNarrator());
        $put('--meta-group', 'group', $book->getSeries());
        $put('--meta-publisher', 'publisher', $book->getPublisher());
        $put('--meta-genre', 'genre', $this->firstGenre($book));
        $put('--meta-description', 'description', $book->getDescription());
        $put('--meta-comment', 'comment', $book->getDescription());

        // TRACK carries the series number for Grimmory, but only a single-file
        // audiobook may receive it: in multi-file books track numbers order
        // playback and must never be touched. tone takes an integer, so
        // non-integral series indexes (e.g. "1.5") are skipped too.
        $seriesIndex = trim((string) $book->getSeriesIndex());
        if ($singleFile && ctype_digit($seriesIndex) && $this->isEmptyMeta($meta['trackNumber'] ?? null)) {
            $flags[] = '--meta-track-number';
            $flags[] = $seriesIndex;
        }

        return $flags;
    }

    /** Empty/absent per tone's dump: missing key, null, blank string, or the 0 an untracked file reports. */
    private function isEmptyMeta(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (\is_string($value)) {
            return trim($value) === '';
        }
        if (\is_int($value) || \is_float($value)) {
            return (float) $value === 0.0;
        }

        return false;
    }

    private function firstGenre(Book $book): ?string
    {
        foreach ($book->getGenres() as $genre) {
            if (trim($genre) !== '') {
                return trim($genre);
            }
        }

        return null;
    }

    /**
     * Run `tone dump <file> --format json` and extract what verification needs.
     * tone exits 0 even for unreadable audio but then omits "audio.duration",
     * so a missing duration also counts as unparseable.
     *
     * @return array{meta: array<string, mixed>, duration: float, chapterCount: int}|null
     */
    private function dump(string $file): ?array
    {
        try {
            $process = new Process(
                [$this->toneBinary, 'dump', $file, '--format', 'json'],
                timeout: self::TONE_TIMEOUT_SECONDS,
            );
            $process->run();
            if (!$process->isSuccessful()) {
                return null;
            }

            $data = json_decode($process->getOutput(), true);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data) || !is_numeric($data['audio']['duration'] ?? null)) {
            return null;
        }

        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $chapters = $meta['chapters'] ?? [];

        return [
            'meta'         => $meta,
            'duration'     => (float) $data['audio']['duration'],
            'chapterCount' => \is_array($chapters) ? \count($chapters) : 0,
        ];
    }

    /**
     * Put the backup's bytes back over the (possibly mangled) file and log why.
     *
     * @param array<string, mixed> $context
     */
    private function restore(string $backup, string $file, string $reason, array $context = []): void
    {
        $restored = @copy($backup, $file);
        $this->logger->warning('Audiobook tag fill rolled back: ' . $reason, $context + [
            'file'     => $file,
            'restored' => $restored,
        ]);
    }

    /**
     * Eligible audio files under $dir (recursive), by lowercased extension.
     *
     * @return list<string> absolute paths, sorted
     */
    private function audioFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && \in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true)) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }
}
