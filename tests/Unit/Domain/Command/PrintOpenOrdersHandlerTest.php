<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersHandler;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;

#[CoversClass(PrintOpenOrdersHandler::class)]
final class PrintOpenOrdersHandlerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageBusInterface&MockObject $messageBus;
    private LockFactory&MockObject $lockFactory;
    private PrintOpenOrdersHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->handler = new PrintOpenOrdersHandler($this->orderRepository, $this->messageBus, $this->lockFactory);
    }

    public function testQueuesADeduplicatedPrintJobForEveryOpenOrder(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['ORDER-001', 'ORDER-002']);

        $dispatched = [];
        $this->messageBus->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(function (PrintOrderCommand $command, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$command, $stamps];

                return new Envelope($command, $stamps);
            });

        ($this->handler)(new PrintOpenOrdersCommand(true));

        self::assertSame(['ORDER-001', 'ORDER-002'], array_map(static fn (array $d) => $d[0]->orderNumber, $dispatched));
        self::assertSame([true, true], array_map(static fn (array $d) => $d[0]->markInProgress, $dispatched));

        foreach ($dispatched as [$command, $stamps]) {
            self::assertCount(1, $stamps);
            $stamp = $stamps[0];
            self::assertInstanceOf(DeduplicateStamp::class, $stamp);
            self::assertSame('print-order-'.$command->orderNumber, (string) $stamp->getKey());
            self::assertSame(7200.0, $stamp->getTtl());
            self::assertFalse($stamp->onlyDeduplicateInQueue(), 'the lock must be held through retries, not only while queued');
        }
    }

    public function testPassesMarkInProgressFlagThrough(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['ORDER-001']);
        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (PrintOrderCommand $c) => false === $c->markInProgress))
            ->willReturnCallback(static fn (object $m, array $stamps) => new Envelope($m, $stamps));

        ($this->handler)(new PrintOpenOrdersCommand(false));
    }

    public function testReleasesTheLockWhenSynchronousDispatchFails(): void
    {
        $this->orderRepository->method('findNewNumbers')->willReturn(['ORDER-001']);
        $this->messageBus->method('dispatch')->willThrowException($failure = new \RuntimeException('printer down'));

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('release');
        $this->lockFactory->expects(self::once())->method('createLockFromKey')
            ->with(self::callback(static fn (Key $key) => 'print-order-ORDER-001' === (string) $key))
            ->willReturn($lock);

        $this->expectExceptionObject($failure);

        ($this->handler)(new PrintOpenOrdersCommand(true));
    }
}
