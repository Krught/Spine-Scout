<?php

namespace App\Twig;

use App\Service\LatestVersionProvider;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes `app_latest_version` to templates, resolved live from GitHub releases
 * (cached) instead of a hard-coded parameter. `app_version` stays a static
 * parameter (it's baked into the build), so only the "latest" side is dynamic.
 *
 * getGlobals() is evaluated lazily on first template render; the provider caches
 * the lookup, so this is a cheap cache read per request, not a GitHub call.
 */
final class VersionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly LatestVersionProvider $latestVersionProvider)
    {
    }

    public function getGlobals(): array
    {
        return [
            'app_latest_version' => $this->latestVersionProvider->getLatestVersion(),
        ];
    }
}
