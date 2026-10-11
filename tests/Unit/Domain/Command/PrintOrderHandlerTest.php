<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOrderHandler;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Service\PrintOrderProcessorInterface;
use Veliu\OrderPrinter\Tests\Support\SpyLogger;

#[CoversClass(PrintOrderHandler::class)]
final class PrintOrderHandlerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private PrintOrderProcessorInterface&MockObject $printOrderProcessor;
    private SpyLogger $logger;
    private PrintOrderHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->printOrderProcessor = $this->createMock(PrintOrderProcessorInterface::class);

        $this->logger = new SpyLogger();
        $this->handler = new PrintOrderHandler($this->orderRepository, $this->printOrderProcessor, $this->logger);
    }

    public function testPrintsTheOrder(): void
    {
        $order = $this->createOrder('ORDER-123');
        $this->orderRepository->expects($this->once())->method('getByOrderNumber')->with('ORDER-123')->willReturn($order);
        $this->printOrderProcessor->expects($this->once())->method('__invoke')->with($order, true);

        ($this->handler)(new PrintOrderCommand('ORDER-123', true));

        self::assertSame([['level' => 'notice', 'message' => 'Order {orderNumber} printed.', 'context' => ['orderNumber' => 'ORDER-123', 'reprint' => false]]], $this->logger->records);
    }

    public function testAReprintRequestPrintsOnlyOnce(): void
    {
        $handler = new PrintOrderHandler($this->orderRepository, $this->printOrderProcessor, $this->logger, new LockFactory(new InMemoryStore()));
        $this->orderRepository->method('getByOrderNumber')->willReturn($this->createOrder('ORDER-123'));
        $this->printOrderProcessor->expects($this->exactly(2))->method('__invoke');

        $handler(new PrintOrderCommand('ORDER-123', true, '2026-10-11T12:00:00.000Z'));
        // same request again (e.g. storing the print time failed): no second receipt
        $handler(new PrintOrderCommand('ORDER-123', true, '2026-10-11T12:00:00.000Z'));
        // a new request prints again
        $handler(new PrintOrderCommand('ORDER-123', true, '2026-10-11T12:05:00.000Z'));

        self::assertSame('Reprint of order {orderNumber} already done.', $this->logger->records[1]['message']);
    }

    public function testAFailedReprintMayBeRetried(): void
    {
        $handler = new PrintOrderHandler($this->orderRepository, $this->printOrderProcessor, $this->logger, new LockFactory(new InMemoryStore()));
        $this->orderRepository->method('getByOrderNumber')->willReturn($this->createOrder('ORDER-123'));
        $calls = 0;
        $this->printOrderProcessor->method('__invoke')->willReturnCallback(static function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new \RuntimeException('Cannot connect to printer');
            }
        });

        try {
            $handler(new PrintOrderCommand('ORDER-123', true, '2026-10-11T12:00:00.000Z'));
        } catch (\RuntimeException) {
        }
        $handler(new PrintOrderCommand('ORDER-123', true, '2026-10-11T12:00:00.000Z'));

        self::assertSame(2, $calls);
    }

    public function testPropagatesPrinterFailuresSoMessengerRetries(): void
    {
        $order = $this->createOrder('ORDER-123');
        $this->orderRepository->method('getByOrderNumber')->willReturn($order);
        $this->printOrderProcessor->method('__invoke')->willThrowException($failure = new \RuntimeException('Cannot connect to printer'));

        try {
            ($this->handler)(new PrintOrderCommand('ORDER-123', true));
            self::fail('expected the printer failure');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->logger->records, 'a failed print must not count as printed');
    }

    public function testUnknownOrderIsNotRetried(): void
    {
        $this->orderRepository->method('getByOrderNumber')->willThrowException(new OrderNotFound('MISSING'));
        $this->printOrderProcessor->expects($this->never())->method('__invoke');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Order "MISSING" not found');

        ($this->handler)(new PrintOrderCommand('MISSING', true));
    }

    private function createOrder(string $number): Order
    {
        return new Order(
            'some-identifier',
            $number,
            null,
            '0.00',
            '0.00',
            new Address('Dwight Schrute', 'Schrute Farm', 'Scranton', '123455656', null),
            [],
            true,
            'Lieferung',
            new \DateTimeImmutable('2025-07-31')
        );
    }
}
