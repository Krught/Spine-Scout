<?php

declare(strict_types=1);

namespace App\Download\Bypass;

use App\Download\Progress\DownloadProgressReporter;
use App\Search\DirectDownload\DirectDownloadConfig;

/**
 * A strategy for fetching the HTML of a page that a plain HTTP GET cannot get
 * past — chiefly the Anna's-Archive slow-partner interstitial when it sits
 * behind a Cloudflare / "just a moment" challenge (a 403 to a scripted client).
 *
 * Implementations are pure transports: given a URL they return the resolved
 * HTML, or null when they are not applicable / cannot resolve it. They never
 * throw — a failure is a null, so the download path fails over to the next
 * candidate link, matching the project ground rule. Each is keyed by mode() and
 * selected by BypassResolver from the operator's DirectDownloadConfig.
 */
interface BypasserInterface
{
    /** The DirectDownloadConfig::BYPASS_* mode this implementation serves. */
    public function mode(): string;

    /** Whether this bypasser can run under the given config (binary present / URL set). */
    public function isConfigured(DirectDownloadConfig $config): bool;

    /**
     * Fetch the resolved HTML for $url, or null when it cannot be obtained.
     * Reports its stages (launching the browser, clearing the challenge) to
     * $progress. Never throws.
     */
    public function fetch(string $url, DirectDownloadConfig $config, DownloadProgressReporter $progress): ?string;
}
