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
 * Behavioural note: each source now performs its OWN independent search and
 * resolves its OWN download links (see App\Search\Source\* adapters). The
 * automatic workflow is a failover cascade in the operator's priority order —
 * the first enabled source that yields a qualifying match wins
 * (DirectDownloadEvaluator). The original design (08) treated LibGen / Z-Library
 * / Welib as md5 download-resolvers for an Anna's-Archive hash; that was
 * superseded by independent per-source search.
 */
enum DirectDownloadSource: string
{
    case AnnasArchive = 'annas_archive';
    case LibGen = 'libgen';
    case ZLibrary = 'zlibrary';
    case Welib = 'welib';
    /** BitTorrent: search the configured indexers and hand the best match to the download client. */
    case Torrent = 'torrent';
    /** MyAnonamouse: search the tracker directly with the operator's session cookie. */
    case Mam = 'mam';

    public function label(): string
    {
        return match ($this) {
            self::AnnasArchive => "Anna's Archive",
            self::LibGen => 'LibGen',
            self::ZLibrary => 'Z-Library',
            self::Welib => 'Welib',
            self::Torrent => 'Torrent',
            self::Mam => 'MyAnonamouse',
        };
    }

    /** Operator-facing hint shown under each mirror textarea. */
    public function help(): string
    {
        return match ($this) {
            self::AnnasArchive => 'Mirror base URLs for an Anna’s-Archive-style HTML index (/search results table, /md5/{hash} download page).',
            self::LibGen => 'LibGen mirror base URLs (search.php results table, ads.php?md5=… → get.php download link).',
            self::ZLibrary => 'Z-Library mirror base URLs (/s/{query} results, book page → /dl/ download link). Publicly accessible — no login required.',
            self::Welib => 'Welib mirror base URLs (Anna’s-Archive-style /search and /md5/{hash} pages).',
            self::Torrent => 'Searches your configured indexers and sends the best match to your download client (Settings → Torrents). No mirror URLs needed.',
            self::Mam => 'Searches MyAnonamouse directly with your session cookie (Settings → MyAnonamouse) and sends the best match to your torrent download client. No mirror URLs needed.',
        };
    }

    /**
     * Whether this source is configured with operator-supplied mirror URLs. Torrent
     * and MyAnonamouse are not — Torrent uses the indexers + download client
     * configured under Settings → Torrents, and MyAnonamouse the session cookie
     * under Settings → MyAnonamouse — so the mirror-URL UI and the HTTP cascade
     * skip them.
     */
    public function usesMirrors(): bool
    {
        return !in_array($this, [self::Torrent, self::Mam], true);
    }

    /**
     * Whether this source performs a search. Every source now searches its own
     * index independently, so this is always true; retained for the settings/dev
     * UI which still reads it.
     */
    public function isSearchSource(): bool
    {
        return true;
    }

    /**
     * Default priority order, used to seed the settings UI and to backfill any
     * sources missing from stored config so all four always render.
     *
     * @return list<self>
     */
    public static function defaultOrder(): array
    {
        return [self::AnnasArchive, self::LibGen, self::ZLibrary, self::Welib, self::Torrent, self::Mam];
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

    /**
     * Ids of the HTTP mirror sources only (excludes Torrent). Used by the mirror-URL
     * UI, the manual interactive search, and the HTTP probe — none of which apply to
     * the torrent source.
     *
     * @return list<string>
     */
    public static function mirrorIds(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::defaultOrder(), static fn (self $s): bool => $s->usesMirrors()),
        ));
    }
}
