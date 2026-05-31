<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;

/**
 * Narrow read seam over the operator's saved search settings: the direct-download
 * mirror config and the best-match policy. Implemented by IntegrationRepository
 * (the single implementation, so Symfony auto-aliases this interface to it).
 *
 * Exists so the search engine and evaluator can depend on just what they read —
 * and so that path stays unit-testable without doubling the final repository.
 */
interface SearchSettingsProvider
{
    public function getDirectDownloadConfig(): DirectDownloadConfig;

    public function getBestMatchPolicy(): BestMatchPolicy;
}
