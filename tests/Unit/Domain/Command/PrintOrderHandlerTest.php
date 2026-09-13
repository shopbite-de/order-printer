<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Domain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOrderHandler;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Service\PrintOrderProcessorInterface;

#[CoversClass(PrintOrderHandler::class)]
final class PrintOrderHandlerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private PrintOrderProcessorInterface&MockObject $printOrderProcessor;
    private PrintOrderHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->printOrderProcessor = $this->createMock(PrintOrderProcessorInterface::class);

        $this->handler = new PrintOrderHandler(
            $this->orderRepository,
            $this->printOrderProcessor
        );
    }

    public function testInvokeLoadsOrderAndHandsItToTheProcessor(): void
    {
        $orderNumber = 'ORDER-123';
        $command = new PrintOrderCommand($orderNumber, true);

        $order = new Order(
            'some-identifier',
            $orderNumber,
            null,
            '0.00',
            '0.00',
            new Address('Dwight Schrute', 'Schrute Farm', 'Scranton', '123455656', null),
            [],
            true,
            'Lieferung',
            new \DateTimeImmutable('2025-07-31')
        );

        $this->orderRepository
            ->expects($this->once())
            ->method('getByOrderNumber')
            ->with($orderNumber)
            ->willReturn($order);

        $this->printOrderProcessor
            ->expects($this->once())
            ->method('__invoke')
            ->with($order, true);

        ($this->handler)($command);
    }
}
