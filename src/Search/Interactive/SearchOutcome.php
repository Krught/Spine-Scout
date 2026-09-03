<?php

declare(strict_types=1);

namespace App\Search\Interactive;

/**
 * The JSON body + HTTP status a search run produced, so the shared runner can
 * report "no mirror configured" / "torrent search is not configured" exactly the
 * way the controllers used to — without either controller having to reimplement
 * the status codes and the caller having to guess which bodies are errors.
 *
 * A plain DTO rather than an exception: the error branches here are ordinary,
 * expected outcomes of a user-driven search (unconfigured source, missing
 * mirror), not exceptional control flow.
 */
final readonly class SearchOutcome
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public array $body,
        public int $status = 200,
    ) {
    }
}
