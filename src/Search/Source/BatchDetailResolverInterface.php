<?php

declare(strict_types=1);

namespace App\Search\Source;

use App\Search\DirectDownload\DirectDownloadConfig;

/**
 * Opt-in companion to ReleaseSourceInterface: resolve MANY candidates' details in
 * one call, so an HTTP-backed source can issue its detail requests concurrently
 * instead of paying one round trip (up to a 30s timeout each) per candidate,
 * serially.
 *
 * Deliberately a separate interface rather than a method on ReleaseSourceInterface:
 * a source that cannot batch (or a test double) stays valid without implementing it,
 * and ReleaseSourceScorer falls back to per-candidate resolveDetail() for those.
 *
 * Contract: the returned map is keyed by the SAME keys as $candidates, each value
 * being exactly what resolveDetail() would have returned for that candidate —
 * including the degraded `error` shape. Never throws; one candidate's transport or
 * parse failure must not affect the others.
 */
interface BatchDetailResolverInterface
{
    /**
     * @param array<int, ReleaseCandidate> $candidates
     *
     * @return array<int, array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}>
     */
    public function resolveDetails(array $candidates, ?DirectDownloadConfig $config = null): array;
}
