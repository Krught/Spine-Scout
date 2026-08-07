<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ask Grimmory (via its native JWT API) to import the sidecar metadata files
 * SpineScout has written next to audiobooks. Carries no payload: the handler
 * re-reads the grimmory Integration row's native-API config at consume time,
 * so a message queued before a settings change still honors the new config.
 */
final readonly class TriggerGrimmorySidecarImport
{
}
