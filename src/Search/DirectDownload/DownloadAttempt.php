<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Search\Source\ReleaseCandidate;

/**
 * One step in the download cascade: a specific item, resolved against a specific
 * mirror of a specific source, with the concrete download link(s) to try. The
 * cascade yields these in order (source → mirror → top-N item); the download
 * handler tries each until one streams a file.
 *
 * Immutable.
 */
final class DownloadAttempt
{
    /**
     * @param list<string> $links Concrete download URLs to try, in order.
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly ReleaseCandidate $item,
        public readonly string $mirror,
        public readonly array $links,
    ) {
    }
}
