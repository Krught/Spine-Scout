<?php

declare(strict_types=1);

namespace App\Tests\Integration\MyAnonamouse;

use App\Integration\MyAnonamouse\MamAccountStateUpdater;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot merge: every account fact is stored, and keys the snapshot does
 * not own pass through untouched.
 */
final class MamAccountStateUpdaterTest extends TestCase
{
    private MamAccountStateUpdater $updater;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->updater = new MamAccountStateUpdater();
        $this->now = new \DateTimeImmutable('2026-08-11T12:00:00+00:00');
    }

    public function testASnapshotStoresEveryFact(): void
    {
        $state = $this->updater->apply([], $this->userInfo(seedbonus: 51234), $this->now);

        self::assertSame('bookmouse', $state['username']);
        self::assertSame('Elite VIP', $state['class']);
        self::assertSame('12.34', $state['ratio']);
        self::assertTrue($state['isVip']);
        self::assertSame(51234, $state['seedbonus']);
        self::assertSame(0, $state['unsatCount']);
        self::assertSame(5, $state['unsatLimit']);
        self::assertNull($state['wedges']);
        self::assertNull($state['vipUntil']);
        self::assertSame('4.2 TiB', $state['uploaded']);
        self::assertSame('349.1 GiB', $state['downloaded']);
        self::assertSame('2026-08-11T12:00:00+00:00', $state['checkedAt']);
    }

    public function testKeysTheSnapshotDoesNotOwnPassThroughUntouched(): void
    {
        $current = [
            'lastIpUpdateOk'      => true,
            'lastIpUpdateAt'      => '2026-08-11T09:00:00+00:00',
            'resolveBackoffUntil' => '2026-08-11T12:15:00+00:00',
        ];

        $state = $this->updater->apply($current, $this->userInfo(seedbonus: 100), $this->now);

        self::assertTrue($state['lastIpUpdateOk']);
        self::assertSame('2026-08-11T09:00:00+00:00', $state['lastIpUpdateAt']);
        self::assertSame('2026-08-11T12:15:00+00:00', $state['resolveBackoffUntil']);
    }

    public function testOptionalFactsAreStoredWhenTheTrackerReportsThem(): void
    {
        $state = $this->updater->apply([], $this->userInfo(seedbonus: 100, wedges: 7, vipUntil: '2027-01-01'), $this->now);

        self::assertSame(7, $state['wedges']);
        self::assertSame('2027-01-01', $state['vipUntil']);
    }

    // -- harness -----------------------------------------------------------

    /**
     * The MyAnonamouseClient::fetchUserInfo() shape with the fixture account's facts.
     *
     * @return array{username: string, class: string, ratio: string|float|null, isVip: bool, seedbonus: ?int, unsatCount: ?int, unsatLimit: ?int, wedges: ?int, vipUntil: ?string, uploaded: ?string, downloaded: ?string}
     */
    private function userInfo(?int $seedbonus, ?int $wedges = null, ?string $vipUntil = null): array
    {
        return [
            'username'   => 'bookmouse',
            'class'      => 'Elite VIP',
            'ratio'      => '12.34',
            'isVip'      => true,
            'seedbonus'  => $seedbonus,
            'unsatCount' => 0,
            'unsatLimit' => 5,
            'wedges'     => $wedges,
            'vipUntil'   => $vipUntil,
            'uploaded'   => '4.2 TiB',
            'downloaded' => '349.1 GiB',
        ];
    }
}
