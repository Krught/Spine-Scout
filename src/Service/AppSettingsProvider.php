<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Narrow read seam over instance-wide ("General" tab) preferences, mirroring
 * SearchSettingsProvider. Implemented by IntegrationRepository (the single
 * implementation, so Symfony auto-aliases this interface to it) — and easy to
 * stub in unit tests without standing up the real repository.
 */
interface AppSettingsProvider
{
    /** Whether downloaded ebooks should have their metadata rewritten from Spine Scout's stored values. */
    public function isMetadataOverwriteEnabled(): bool;
}
