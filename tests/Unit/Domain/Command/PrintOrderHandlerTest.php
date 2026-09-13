<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOrderHandler;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJobRepositoryInterface;
use Veliu\OrderPrinter\Domain\Service\PrintOrderProcessorInterface;

#[CoversClass(PrintOrderHandler::class)]
final class PrintOrderHandlerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private PrintOrderProcessorInterface&MockObject $printOrderProcessor;
    private PrintJobRepositoryInterface&MockObject $printJobs;
    private PrintOrderHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->printOrderProcessor = $this->createMock(PrintOrderProcessorInterface::class);
        $this->printJobs = $this->createMock(PrintJobRepositoryInterface::class);

        $this->handler = new PrintOrderHandler($this->orderRepository, $this->printOrderProcessor, $this->printJobs);
    }

    public function testPrintsTheOrderAndReleasesTheJob(): void
    {
        $order = $this->createOrder('ORDER-123');
        $this->orderRepository->expects($this->once())->method('getByOrderNumber')->with('ORDER-123')->willReturn($order);
        $this->printOrderProcessor->expects($this->once())->method('__invoke')->with($order, true);
        $this->printJobs->expects($this->once())->method('finish')->with('ORDER-123');

        ($this->handler)(new PrintOrderCommand('ORDER-123', true));
    }

    public function testKeepsTheJobWhenPrintingFails(): void
    {
        $order = $this->createOrder('ORDER-123');
        $this->orderRepository->method('getByOrderNumber')->willReturn($order);
        $this->printOrderProcessor->method('__invoke')->willThrowException($failure = new \RuntimeException('Cannot connect to printer'));
        $this->printJobs->expects($this->never())->method('finish');

        $this->expectExceptionObject($failure);

        ($this->handler)(new PrintOrderCommand('ORDER-123', true));
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
