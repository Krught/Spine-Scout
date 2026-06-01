<?php

declare(strict_types=1);

namespace App\Search\Source;

use App\Search\DirectDownload\DirectDownloadConfig;

/**
 * Plugin contract for a release source: given a book, return downloadable candidates.
 *
 * Implementations are autoconfigured with the `app.release_source` tag
 * (see config/services.yaml _instanceof block) and injected as a TaggedIterator
 * into the search dispatcher. This interface is the seam DirectHttpSource
 * (and any future Prowlarr / Newznab / IRC adapter) hangs off of.
 */
interface ReleaseSourceInterface
{
    /**
     * Stable identifier used in DownloadJob.source, the best-match source-priority
     * list, etc. Lowercase, snake_case. Historically protocol-shaped
     * (e.g. 'direct_http'); new sources use their DirectDownloadSource id so the
     * best-match and direct-download id namespaces line up.
     */
    public function getName(): string;

    /**
     * The DirectDownloadSource enum value this source maps to — the key the
     * direct-download config uses for isIndexerEnabled()/mirrorsFor() and the
     * operator's indexerPriority cascade order. Distinct from getName() only for
     * Anna's Archive (getName() 'direct_http' → sourceId() 'annas_archive').
     */
    public function sourceId(): string;

    /**
     * Human-friendly label for the settings UI.
     */
    public function getDisplayName(): string;

    /**
     * True when this source has enough configuration to attempt a search.
     * False when it's disabled, missing credentials, missing mirrors, etc.
     * The settings UI calls this to render "locked with reason"-style hints.
     */
    public function isAvailable(): bool;

    /**
     * If !isAvailable(), a short human-readable reason ("Add at least one
     * mirror"). Null when available.
     */
    public function getUnavailableReason(): ?string;

    /**
     * Search this source for releases of the planned book.
     *
     * $config overrides the saved direct-download config for this call (used by
     * the dev probe to apply ephemeral enable/disable toggles without persisting);
     * null reads the operator's saved config.
     *
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array;

    /**
     * The search URL this source would request for $plan, without performing it —
     * the chosen mirror + full query URL, or nulls when the source has no usable
     * mirror. Powers the dev probe's "Generate URL" and per-source reporting.
     * $config: see search().
     *
     * @return array{mirror: string|null, url: string|null}
     */
    public function searchPlanUrl(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array;

    /**
     * The search URL for one specific mirror base (pure, no I/O) — lets the
     * download cascade log each mirror it is about to search.
     */
    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string;

    /**
     * Search a SINGLE mirror (no internal cascade). Never throws — returns [] on
     * transport/parse failure or no results. The download cascade calls this once
     * per mirror so each attempt is visible in the activity log; search() loops it
     * across the configured mirrors and returns the first non-empty result.
     *
     * @return list<ReleaseCandidate>
     */
    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array;

    /**
     * Resolve one candidate's detail: verified ISBNs (search rows rarely carry
     * them), the raw label→values metadata, and the concrete download links the
     * source exposes for it. The source locates its own mirror from the
     * candidate (e.g. $candidate->extra['mirror']). Never throws — transport or
     * parse failure comes back as a non-null `error` so the caller can fail over.
     * $config: see search().
     *
     * @return array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}
     */
    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array;

    /**
     * Resolve the concrete download link(s) for $item against a SPECIFIC mirror
     * base URL — the download cascade retries an item across each of a source's
     * mirrors. Identifies the record by its stable key ($item->sourceId / infoUrl)
     * so it works for any of the source's mirrors, not just the one it was found
     * on. Never throws — returns [] on transport/parse failure so the cascade
     * moves on. $config: see search().
     *
     * @return list<string>
     */
    public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array;
}
