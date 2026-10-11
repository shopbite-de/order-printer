<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware;

use Psr\Clock\ClockInterface;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Api\ClientInterface;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderItem;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptPositionPrintTypeEnum;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindOrderNumbersRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindOrderNumbersResponse;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindReprintRequestsRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\FindReprintRequestsResponse;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\MarkPrintedRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\Order\UpdateOrderStateRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery\FindDeliveriesRequest;
use Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery\FindDeliveriesResponse;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\OrderDelivery;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\OrderLineItem;

/** @psalm-api  */
final readonly class OrderRepository implements OrderRepositoryInterface
{
    /** "Jetzt drucken" requests older than this are ignored (a forgotten request never prints days later). */
    public const string REPRINT_WINDOW = 'PT24H';

    public function __construct(
        private ClientInterface $client,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function findReprintRequests(): array
    {
        $since = $this->clock->now()->sub(new \DateInterval(self::REPRINT_WINDOW));

        return FindReprintRequestsResponse::fromResponse(
            $this->client->request(new FindReprintRequestsRequest($since))
        )->requests;
    }

    #[\Override]
    public function markPrinted(Order $order): void
    {
        $this->client->request(new MarkPrintedRequest($order->identifier, $this->clock->now()));
    }

    #[\Override]
    public function markInProgress(Order $order): void
    {
        $this->client->request(new UpdateOrderStateRequest($order->identifier, OrderStateEnum::PROCESS));
    }

    #[\Override]
    public function getByOrderNumber(string $number): Order
    {
        $response = FindDeliveriesResponse::fromResponse(
            $this->client->request(new FindDeliveriesRequest(orderNumber: $number))
        );

        if (!$delivery = $response->first()) {
            throw new OrderNotFound($number);
        }

        return self::transform($delivery);
    }

    #[\Override]
    public function findNewNumbers(): array
    {
        $response = FindOrderNumbersResponse::fromResponse(
            $this->client->request(new FindOrderNumbersRequest(state: OrderStateEnum::OPEN))
        );

        return $response->orderNumbers;
    }

    public static function transform(OrderDelivery $orderDelivery): Order
    {
        $containerLineItems = array_values(array_filter($orderDelivery->order->lineItems, static fn (OrderLineItem $lineItem) => null === $lineItem->parentId));

        $address = new Address(
            sprintf(
                '%s %s',
                $orderDelivery->shippingOrderAddress->firstName,
                $orderDelivery->shippingOrderAddress->lastName
            ),
            $orderDelivery->shippingOrderAddress->street,
            $orderDelivery->shippingOrderAddress->city,
            $orderDelivery->shippingOrderAddress->phoneNumber,
            $orderDelivery->shippingOrderAddress->additionalAddressLine1,
        );

        return new Order(
            $orderDelivery->order->id,
            $orderDelivery->order->orderNumber,
            $orderDelivery->order->customerComment,
            number_format($orderDelivery->order->amountTotal, 2, ',', ''),
            number_format($orderDelivery->shippingCosts->totalPrice, 2, ',', ''),
            $address,
            array_map(fn (OrderLineItem $item) => self::transformItem($item), $containerLineItems),
            OrderStateEnum::OPEN === $orderDelivery->order->stateMachineState->technicalName,
            $orderDelivery->shippingMethod->name,
            $orderDelivery->order->createdAt,
        );
    }

    private static function transformItem(OrderLineItem $item): OrderItem
    {
        return new OrderItem(
            $item->productNumber,
            $item->label,
            number_format($item->totalPrice, 2, ',', ''),
            $item->quantity,
            $item->printType ?? ReceiptPositionPrintTypeEnum::LABEL
        );
    }
}
