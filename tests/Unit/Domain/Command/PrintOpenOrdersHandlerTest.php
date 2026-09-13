<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersHandler;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJobRepositoryInterface;

#[CoversClass(PrintOpenOrdersHandler::class)]
final class PrintOpenOrdersHandlerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private PrintJobRepositoryInterface&MockObject $printJobs;
    private MessageBusInterface&MockObject $messageBus;
    private PrintOpenOrdersHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->printJobs = $this->createMock(PrintJobRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->handler = new PrintOpenOrdersHandler($this->orderRepository, $this->printJobs, $this->messageBus);
    }

    public function testQueuesAPrintJobForEveryOpenOrder(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['ORDER-001', 'ORDER-002']);
        $this->printJobs->method('isPending')->willReturn(false);

        $started = [];
        $this->printJobs->expects(self::exactly(2))->method('start')
            ->willReturnCallback(function (string $orderNumber) use (&$started): void { $started[] = $orderNumber; });

        $dispatched = [];
        $this->messageBus->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(function (PrintOrderCommand $command) use (&$dispatched): Envelope {
                $dispatched[] = $command;

                return new Envelope($command);
            });

        ($this->handler)(new PrintOpenOrdersCommand(true));

        self::assertSame(['ORDER-001', 'ORDER-002'], $started);
        self::assertSame(['ORDER-001', 'ORDER-002'], array_map(static fn (PrintOrderCommand $c) => $c->orderNumber, $dispatched));
        self::assertSame([true, true], array_map(static fn (PrintOrderCommand $c) => $c->markInProgress, $dispatched));
    }

    public function testSkipsOrdersWhosePrintJobIsStillPending(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['PENDING', 'FRESH']);
        $this->printJobs->method('isPending')->willReturnMap([['PENDING', true], ['FRESH', false]]);

        $this->printJobs->expects(self::once())->method('start')->with('FRESH');
        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (PrintOrderCommand $c) => 'FRESH' === $c->orderNumber && false === $c->markInProgress))
            ->willReturnCallback(static fn (object $m) => new Envelope($m));

        ($this->handler)(new PrintOpenOrdersCommand(false));
    }

    public function testReleasesTheJobWhenSynchronousDispatchFails(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['ORDER-001']);
        $this->printJobs->method('isPending')->willReturn(false);
        $this->printJobs->expects(self::once())->method('start')->with('ORDER-001');
        $this->messageBus->method('dispatch')->willThrowException($failure = new \RuntimeException('printer down'));
        $this->printJobs->expects(self::once())->method('finish')->with('ORDER-001');

        $this->expectExceptionObject($failure);

        ($this->handler)(new PrintOpenOrdersCommand(true));
    }
}
