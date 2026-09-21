<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Symfony\Scheduler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Command\PurgeReceiptsCommand;
use Veliu\OrderPrinter\Infra\Symfony\Scheduler\OpenOrderProvider;

#[CoversClass(OpenOrderProvider::class)]
final class OpenOrderProviderTest extends TestCase
{
    public function testPollsEveryTenSecondsAndPurgesReceiptsDaily(): void
    {
        $recurring = new OpenOrderProvider(30)->getSchedule()->getRecurringMessages();
        $messages = array_map(self::message(...), $recurring);

        self::assertCount(2, $messages);
        self::assertInstanceOf(PrintOpenOrdersCommand::class, $messages[0]);
        self::assertTrue($messages[0]->markInProgress);
        self::assertInstanceOf(PurgeReceiptsCommand::class, $messages[1]);
        self::assertSame(30, $messages[1]->retentionDays);
    }

    public function testZeroRetentionKeepsReceiptsForever(): void
    {
        $recurring = new OpenOrderProvider(0)->getSchedule()->getRecurringMessages();

        self::assertCount(1, $recurring);
        self::assertInstanceOf(PrintOpenOrdersCommand::class, self::message($recurring[0]));
    }

    private static function message(RecurringMessage $recurring): object
    {
        $now = new \DateTimeImmutable();
        $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), $now);
        $messages = [...$recurring->getMessages($context)];
        self::assertCount(1, $messages);

        return $messages[0];
    }
}
