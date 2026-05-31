<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Periodic sweep: re-dispatch a release search for every approved request that
 * still has no in-progress or completed download (i.e. we never found a match).
 * Runs on a schedule so a book that wasn't available at approval time keeps
 * getting retried until a release shows up.
 */
final readonly class RetryApprovedSearches
{
}
