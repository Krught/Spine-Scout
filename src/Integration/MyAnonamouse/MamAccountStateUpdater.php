<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

/**
 * Folds one jsonLoad.php snapshot into the stored account state (Integration
 * options['account']): the account facts the settings page shows (username, class,
 * ratio, VIP, bonus points, wedges, unsat counts, transfer totals).
 *
 * apply() is pure — same inputs, same output, no I/O — so the fold is
 * unit-testable on the host.
 */
final class MamAccountStateUpdater
{
    /**
     * Merges $userInfo (the MyAnonamouseClient::fetchUserInfo() shape) into
     * $currentState. Keys the snapshot does not own (lastIpUpdateOk,
     * resolveBackoffUntil, …) pass through untouched.
     *
     * @param array<string, mixed> $currentState
     * @param array{username: string, class: string, ratio: string|float|null, isVip: bool, seedbonus: ?int, unsatCount: ?int, unsatLimit: ?int, wedges: ?int, vipUntil: ?string, uploaded: ?string, downloaded: ?string} $userInfo
     *
     * @return array<string, mixed>
     */
    public function apply(array $currentState, array $userInfo, \DateTimeImmutable $now): array
    {
        $state = $currentState;

        $state['username']   = $userInfo['username'];
        $state['class']      = $userInfo['class'];
        $state['ratio']      = $userInfo['ratio'];
        $state['isVip']      = $userInfo['isVip'];
        $state['seedbonus']  = $userInfo['seedbonus'];
        $state['unsatCount'] = $userInfo['unsatCount'];
        $state['unsatLimit'] = $userInfo['unsatLimit'];
        $state['wedges']     = $userInfo['wedges'];
        $state['vipUntil']   = $userInfo['vipUntil'];
        $state['uploaded']   = $userInfo['uploaded'];
        $state['downloaded'] = $userInfo['downloaded'];
        $state['checkedAt']  = $now->format(\DateTimeInterface::ATOM);

        return $state;
    }
}
