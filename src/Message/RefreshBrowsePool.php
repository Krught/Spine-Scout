<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Rebuilds one of the /browse row pools in the worker.
 *
 * Dispatched by {@see \App\Service\BrowsePoolCache} when it serves a pool that is older than the
 * operator's browse refresh threshold: the request returns the stale rows immediately and the
 * next one picks up whatever this landed. Carries only what identifies the pool — the depth to
 * rebuild comes from the stored pool itself, so a queued message can't pin a stale row count.
 */
final readonly class RefreshBrowsePool
{
    public const POOL_TRENDING = 'trending';
    public const POOL_SEARCH = 'search';

    public function __construct(
        public string $pool = self::POOL_TRENDING,
        /** Search pools only: the query and the search type ('title', 'author', …) that key it. */
        public string $query = '',
        public string $type = 'title',
    ) {
    }
}
