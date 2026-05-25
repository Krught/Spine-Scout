<?php

declare(strict_types=1);

namespace App\Integration\Grimmory;

final readonly class SyncResult
{
    /**
     * @param list<string> $newExternalIds external ids of books inserted on this run; used to
     *                                     pre-warm cover caches without scanning the whole library.
     */
    public function __construct(
        public bool $ran,
        public int $added = 0,
        public int $updated = 0,
        public int $removed = 0,
        public int $seen = 0,
        public array $newExternalIds = [],
    ) {
    }

    public static function skipped(): self
    {
        return new self(false);
    }
}
