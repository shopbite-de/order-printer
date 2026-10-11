<?php

declare(strict_types=1);

namespace Tests\Veliu\OrderPrinter\Infra\Shopware;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Api\ApiResponse;
use Veliu\OrderPrinter\Domain\Api\ClientInterface;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindOrderNumbersRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindReprintRequestsRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\MarkPrintedRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\UpdateOrderStateRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery\FindDeliveriesRequest;
use Veliu\OrderPrinter\Infra\Shopware\OrderRepository;
use Veliu\OrderPrinter\Infra\Shopware\OrderStateEnum;

/** @covers \Veliu\OrderPrinter\Infra\Shopware\OrderRepository */
class OrderRepositoryTest extends TestCase
{
    private ClientInterface|MockObject $client;
    private OrderRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->repository = new OrderRepository($this->client, new MockClock('2026-10-11 12:00:00', 'UTC'));
    }

    public function testGetByOrderNumberSuccessfully(): void
    {
        $orderNumber = '10001';
        $orderDelivery = $this->createOrderDelivery($orderNumber);

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with($this->isInstanceOf(FindDeliveriesRequest::class))
            ->willReturn(new ApiResponse(200, ['total' => 1, 'data' => [$orderDelivery]]));

        $order = $this->repository->getByOrderNumber($orderNumber);

        $this->assertEquals($orderNumber, $order->number);
        $this->assertEquals('1000,00', $order->totalPrice);
        $this->assertEquals('50,00', $order->shippingCost);
        $this->assertEquals('John Doe', $order->address->name);
        $this->assertCount(1, $order->items);
    }

    public function testGetByOrderNumberThrowsExceptionWhenNotFound(): void
    {
        $orderNumber = '99999';

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with($this->isInstanceOf(FindDeliveriesRequest::class))
            ->willReturn(new ApiResponse(200, ['total' => 0, 'data' => []]));

        $this->expectException(OrderNotFound::class);
        $this->repository->getByOrderNumber($orderNumber);
    }

    public function testMarkInProgress(): void
    {
        $order = new Order(
            'order-id-1',
            '10001',
            null,
            '1000,00',
            '50,00',
            new Address('John Doe', 'Test Street 1', 'Test City', '+1234567890', null),
            [],
            true,
            'Lieferung',
            new \DateTimeImmutable('2025-07-31'),
        );

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with($this->isInstanceOf(UpdateOrderStateRequest::class))
            ->willReturn(new ApiResponse(200, []));

        $this->repository->markInProgress($order);
    }

    public function testFindNewNumbers(): void
    {
        $expectedNumbers = ['10001', '10002'];

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with($this->isInstanceOf(FindOrderNumbersRequest::class))
            ->willReturn(new ApiResponse(200, ['total' => 1, 'data' => [
                ['orderNumber' => '10001'],
                ['orderNumber' => '10002'],
            ]]));

        $numbers = $this->repository->findNewNumbers();

        $this->assertEquals($expectedNumbers, $numbers);
    }

    private function createOrderDelivery(string $orderNumber): array
    {
        return [
            'order' => [
                'id' => 'order-id-1',
                'orderNumber' => $orderNumber,
                'amountTotal' => 1000.00,
                'lineItems' => [
                    [
                        'id' => 'line-item-id-1',
                        'parentId' => null,
                        'type' => 'container',
                        'productNumber' => 'PROD-001',
                        'label' => 'Test Product',
                        'totalPrice' => 100.00,
                        'quantity' => 1,
                        'payload' => [
                            'productNumber' => '21',
                        ],
                    ],
                ],
                'stateMachineState' => [
                    'technicalName' => OrderStateEnum::OPEN->value,
                ],
                'createdAt' => '2025-07-31T12:00:00+00:00',
                'customerComment' => null,
            ],
            'shippingOrderAddress' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'street' => 'Test Street 1',
                'city' => 'Test City',
                'phoneNumber' => '+1234567890',
                'additionalAddressLine1' => null,
            ],
            'shippingCosts' => [
                'totalPrice' => 50.00,
            ],
            'shippingMethod' => ['name' => 'Lieferung'],
        ];
    }

    public function testFindsReprintRequestsNewerThanTheLastPrint(): void
    {
        $this->client->expects($this->once())->method('request')
            ->with($this->callback(static function (FindReprintRequestsRequest $request): bool {
                self::assertSame('2026-10-10T12:00:00+00:00', $request->getBody()['filter'][0]['parameters']['gte']);

                return true;
            }))
            ->willReturn(new ApiResponse(200, ['total' => 3, 'data' => [
                ['orderNumber' => '10001', 'customFields' => ['shopbite_print_requested_at' => '2026-10-11T11:00:00.000Z']],
                ['orderNumber' => '10002', 'customFields' => ['shopbite_print_requested_at' => '2026-10-11T11:00:00.000Z', 'shopbite_printed_at' => '2026-10-11T10:00:00.000Z']],
                ['orderNumber' => '10003', 'customFields' => ['shopbite_print_requested_at' => '2026-10-11T11:00:00.000Z', 'shopbite_printed_at' => '2026-10-11T11:00:05.000Z']],
                ['orderNumber' => '10004', 'customFields' => null],
            ]]));

        $requests = $this->repository->findReprintRequests();

        self::assertSame(['10001', '10002'], array_map(static fn ($r) => $r->orderNumber, $requests));
    }

    public function testMarkPrintedStoresTheTimeInUtc(): void
    {
        $order = new Order('a1b2', '10001', null, '10,00', '0,00', new Address('A B', 'S 1', 'C', '0123', null), [], false, 'Lieferung', new \DateTimeImmutable());
        $this->client->expects($this->once())->method('request')
            ->with($this->callback(static function (MarkPrintedRequest $request): bool {
                self::assertSame('PATCH', $request->getMethod());
                self::assertSame('/api/order/a1b2', $request->getUri());
                self::assertSame(['customFields' => ['shopbite_printed_at' => '2026-10-11T12:00:00.000Z']], $request->getBody());

                return true;
            }))
            ->willReturn(new ApiResponse(204, []));

        $this->repository->markPrinted($order);
    }
}
