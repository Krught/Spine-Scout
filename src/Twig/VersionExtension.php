<?php

namespace App\Twig;

use App\Service\LatestVersionProvider;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes `app_version_status` to templates: the build channel (dev/nightly/
 * release), the number to display, and whether a newer stable release exists.
 * The "latest" side is resolved live from GitHub releases (cached); `app_version`
 * stays a static parameter (baked into the build).
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
            'app_version_status' => $this->latestVersionProvider->getStatus(),
        ];
    }
}
