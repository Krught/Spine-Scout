<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;

/**
 * Narrow seam over the operator's saved search settings: the direct-download
 * mirror config, the best-match policy, and the automatic-fulfillment toggle
 * (the only writable one). Implemented by IntegrationRepository
 * (the single implementation, so Symfony auto-aliases this interface to it).
 *
 * Exists so the search engine and evaluator can depend on just what they read —
 * and so that path stays unit-testable without doubling the final repository.
 */
interface SearchSettingsProvider
{
    public function getDirectDownloadConfig(): DirectDownloadConfig;

    public function getBestMatchPolicy(): BestMatchPolicy;

    /**
     * Operator toggle: is the automatic search/fulfillment pipeline enabled?
     * True when unset, so a fresh install fulfils automatically. When false, no
     * automatic initiator (approve dispatch, retry sweep) may create download jobs;
     * the manual interactive-search path is unaffected.
     */
    public function isAutomaticFulfillmentEnabled(): bool;

    /** Persists the toggle above. */
    public function setAutomaticFulfillmentEnabled(bool $enabled): void;
}
