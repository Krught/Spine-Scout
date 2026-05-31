<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

/**
 * The fixed set of direct-download mirror source types Spine Scout knows how to
 * talk to. Each is a named section in Settings → Direct downloads with its own
 * operator-supplied mirror URL list.
 *
 * NOTE ON BRAND NAMES: these are real brands. The project originally forbade
 * naming any mirror brand in `src/`; that rule was deliberately reversed (see
 * shelfmark-research/README.md "Ground rules"). Brand *labels* are now allowed
 * in code. What is still NEVER shipped is a default mirror **URL/domain** — the
 * mirror lists ship empty and are populated by the operator at runtime.
 *
 * Behavioural note (for the future engine, not the settings UI): these sources
 * are not symmetric. Anna's Archive is the *search* front-end — it returns
 * content-hash records. LibGen / Z-Library / Welib are download-link
 * *resolvers* for a hash. The priority order therefore mostly governs
 * download-resolution order. See shelfmark-research/04 + 08.
 */
enum DirectDownloadSource: string
{
    case AnnasArchive = 'annas_archive';
    case LibGen = 'libgen';
    case ZLibrary = 'zlibrary';
    case Welib = 'welib';

    public function label(): string
    {
        return match ($this) {
            self::AnnasArchive => "Anna's Archive",
            self::LibGen => 'LibGen',
            self::ZLibrary => 'Z-Library',
            self::Welib => 'Welib',
        };
    }

    /** Operator-facing hint shown under each mirror textarea. */
    public function help(): string
    {
        return match ($this) {
            self::AnnasArchive => 'Mirror base URLs for an Anna’s-Archive-style HTML index. This source performs the search; the others resolve downloads for what it finds.',
            self::LibGen => 'LibGen mirror base URLs. Used to resolve a download link (get.php?md5=…) for a found record.',
            self::ZLibrary => 'Z-Library mirror base URLs. Used to resolve an /md5/{hash} download for a found record.',
            self::Welib => 'Welib mirror base URLs. Used to resolve an /md5/{hash} download for a found record.',
        };
    }

    /** Whether this source performs the search (vs. only resolving downloads). */
    public function isSearchSource(): bool
    {
        return $this === self::AnnasArchive;
    }

    /**
     * Default priority order, used to seed the settings UI and to backfill any
     * sources missing from stored config so all four always render.
     *
     * @return list<self>
     */
    public static function defaultOrder(): array
    {
        return [self::AnnasArchive, self::LibGen, self::ZLibrary, self::Welib];
    }

    public static function tryFromId(string $id): ?self
    {
        return self::tryFrom($id);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::defaultOrder());
    }
}
