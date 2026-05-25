<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Triggers garbage collection of expired rows in the sessions table.
 * Dispatched by the scheduler since framework.session.gc_probability is 0.
 */
final readonly class PurgeExpiredSessions
{
}
