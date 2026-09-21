<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Veliu\OrderPrinter\Domain\Command\PurgeReceiptsCommand;
use Veliu\OrderPrinter\Domain\Command\PurgeReceiptsHandler;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptArchiveInterface;
use Veliu\OrderPrinter\Tests\Support\SpyLogger;

#[CoversClass(PurgeReceiptsHandler::class)]
final class PurgeReceiptsHandlerTest extends TestCase
{
    public function testPurgesReceiptsOlderThanTheRetentionPeriodAndLogsTheCount(): void
    {
        $archive = $this->createMock(ReceiptArchiveInterface::class);
        $archive->expects(self::once())->method('purgeOlderThan')
            ->with(new \DateTimeImmutable('2026-08-22 04:00:00'))
            ->willReturn(7);
        $logger = new SpyLogger();

        $handler = new PurgeReceiptsHandler($archive, new MockClock('2026-09-21 04:00:00'), $logger);
        $handler(new PurgeReceiptsCommand(30));

        self::assertSame(['info'], array_column($logger->records, 'level'));
        self::assertSame('Deleted 7 receipt copies older than 30 days.', $logger->records[0]['message']);
        self::assertSame(['deleted' => 7, 'retentionDays' => 30], $logger->records[0]['context']);
    }
}
