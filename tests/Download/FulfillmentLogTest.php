<?php

declare(strict_types=1);

namespace App\Tests\Download;

use App\Download\FulfillmentLog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FulfillmentLogTest extends TestCase
{
    public function testPruneDeletesRowsOlderThanCutoff(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured = ['sql' => $sql, 'params' => $params];

                return 5;
            });

        $log = new FulfillmentLog($connection, new NullLogger());
        $deleted = $log->pruneOlderThan(7200);

        self::assertSame(5, $deleted);
        self::assertStringContainsStringIgnoringCase('delete from fulfillment_events', $captured['sql']);
        self::assertArrayHasKey('cutoff', $captured['params']);
        // Cutoff is ~2h in the past (allow a wide window so the test isn't clocky).
        $cutoff = strtotime((string) $captured['params']['cutoff']);
        self::assertGreaterThan(time() - 7200 - 60, $cutoff);
        self::assertLessThan(time() - 7200 + 60, $cutoff);
    }

    public function testPruneNeverThrows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('db down'));

        $log = new FulfillmentLog($connection, new NullLogger());
        self::assertSame(0, $log->pruneOlderThan(7200));
    }
}
