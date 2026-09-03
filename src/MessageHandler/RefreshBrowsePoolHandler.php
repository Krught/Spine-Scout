<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshBrowsePool;
use App\Service\BrowsePoolCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the background half of the browse pools' stale-while-revalidate. Every guarantee lives in
 * {@see BrowsePoolCache} — never shrink the pool, never store an error-emptied one, always
 * release the in-flight marker — so this handler is deliberately a thin dispatch on pool kind.
 */
#[AsMessageHandler]
final class RefreshBrowsePoolHandler
{
    public function __construct(
        private readonly BrowsePoolCache $pools,
    ) {
    }

    public function __invoke(RefreshBrowsePool $message): void
    {
        // A search pool with no query can't be keyed; drop it rather than rebuilding trending
        // under a search message (which would double-refresh the trending pool).
        if ($message->pool === RefreshBrowsePool::POOL_SEARCH) {
            if (trim($message->query) === '') {
                return;
            }
            $this->pools->refreshSearch($message->query, $message->type);

            return;
        }

        $this->pools->refreshTrending();
    }
}
