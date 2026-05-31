<?php

declare(strict_types=1);

namespace App\Search\Source;

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
     * Stable identifier used in DownloadJob.source, settings priority lists, etc.
     * Lowercase, snake_case. Should describe the protocol shape, not a brand
     * (per project ground rule — see shelfmark-research/04).
     */
    public function getName(): string;

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
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan): array;
}
