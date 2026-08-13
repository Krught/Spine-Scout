<?php

declare(strict_types=1);

namespace App\Search\Mam;

use App\Integration\MyAnonamouse\MamRelease;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Source\ReleaseCandidate;

/**
 * Maps MyAnonamouse search rows to the neutral ReleaseCandidate shape the torrent
 * scorer and the interactive-search panel already understand. Pure — no I/O,
 * static — so the mapping is unit-testable, mirroring ProwlarrClient::mapResults.
 *
 * Deliberately NOT a ReleaseSourceInterface implementation: that contract is
 * mirror-oriented (HTTP downloads keyed by ISBN), while MAM rows are torrent
 * grabs that bypass the mirror cascade the same way the Prowlarr source does.
 */
final class MamCandidateMapper
{
    /** The indexer label the panel shows for every MAM row. */
    public const INDEXER = 'MyAnonamouse';

    /**
     * @param list<MamRelease> $releases
     *
     * @return list<ReleaseCandidate>
     */
    public static function mapAll(array $releases, string $baseUrl): array
    {
        return array_map(
            static fn (MamRelease $release): ReleaseCandidate => self::map($release, $baseUrl),
            array_values($releases),
        );
    }

    public static function map(MamRelease $release, string $baseUrl): ReleaseCandidate
    {
        $contentType = $release->audiobook
            ? ReleaseCandidate::CONTENT_AUDIOBOOK
            : ReleaseCandidate::CONTENT_EBOOK;

        return new ReleaseCandidate(
            source: DirectDownloadSource::Mam->value,
            sourceId: (string) $release->mamTorrentId,
            title: $release->title,
            format: self::firstFormat($release->filetypes),
            language: null,
            sizeBytes: $release->sizeBytes,
            downloadUrl: $release->downloadUrl($baseUrl),
            infoUrl: rtrim($baseUrl, '/') . '/t/' . $release->mamTorrentId,
            protocol: ReleaseCandidate::PROTOCOL_TORRENT,
            indexer: self::INDEXER,
            seeders: $release->seeders,
            downloads: $release->timesCompleted,
            contentType: $contentType,
            author: $release->authors !== [] ? implode(', ', $release->authors) : null,
            isbns: [],
            publisher: null,
            year: null,
            extra: [
                'leechers'    => $release->leechers,
                'flags'       => self::flags($release),
                'categories'  => $release->catName !== null ? [$release->catName] : [],
                'type'        => $contentType,
                'publishDate' => $release->addedAt?->format('Y-m-d'),
                // MAM's filetype column is multi-valued; the 16-char format field
                // above only keeps the first token, the full string rides along here.
                'filetypes'   => $release->filetypes,
                'mam'         => [
                    'torrentId'         => $release->mamTorrentId,
                    'dlHash'            => (string) $release->dlHash,
                    'free'              => $release->free,
                    'flVip'             => $release->flVip,
                    'personalFreeleech' => $release->personalFreeleech,
                ],
            ],
        );
    }

    /**
     * The panel's flag chips. `freeleech` reuses the existing chip styling; the
     * VIP/personal variants get their own modifiers in the interactive search UI.
     *
     * Only the strongest freeleech reason becomes a chip: MAM stamps `fl_vip` on
     * effectively the whole tracker, so a sitewide-free or personal-freeleech row
     * would otherwise always show the VIP chip too and read as "VIP freeleech"
     * when it is plain freeleech for everyone.
     *
     * @return list<string>
     */
    private static function flags(MamRelease $release): array
    {
        $flags = [];
        if ($release->free) {
            $flags[] = 'freeleech';
        } elseif ($release->personalFreeleech) {
            $flags[] = 'personal_freeleech';
        } elseif ($release->flVip) {
            $flags[] = 'vip_freeleech';
        }
        if ($release->vip) {
            $flags[] = 'vip';
        }

        return $flags;
    }

    /**
     * First token of MAM's multi-valued filetype column ("epub mobi pdf"),
     * lowercased — the only piece that fits the job's 16-char format column and
     * the scorer's per-extension format rank.
     */
    private static function firstFormat(?string $filetypes): ?string
    {
        if ($filetypes === null) {
            return null;
        }
        $tokens = preg_split('/[\s,\/;]+/', trim($filetypes), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }

        return strtolower($tokens[0]);
    }
}
