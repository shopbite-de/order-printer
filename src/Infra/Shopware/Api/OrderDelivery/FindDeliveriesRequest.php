<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery;

use Veliu\OrderPrinter\Domain\Api\HasNoAdditionalHeadersTrait;
use Veliu\OrderPrinter\Domain\Api\IsPostTrait;
use Veliu\OrderPrinter\Domain\Api\RequestInterface;
use Veliu\OrderPrinter\Infra\Shopware\OrderStateEnum;

final readonly class FindDeliveriesRequest implements RequestInterface
{
    use IsPostTrait;
    use HasNoAdditionalHeadersTrait;

    /** @psalm-param non-empty-string $orderNumber */
    public function __construct(
        public ?string $orderNumber = null,
        public ?OrderStateEnum $state = null,
    ) {
    }

    #[\Override]
    public function getUri(): string
    {
        return '/api/search/order-delivery';
    }

    #[\Override]
    public function getBody(): ?array
    {
        $body = [
            'includes' => [
                'order_delivery' => ['id', 'order', 'shippingCosts', 'shippingOrderAddress', 'shippingMethod', 'extensions'],
                'order' => ['id', 'orderNumber', 'amountTotal', 'lineItems', 'stateMachineState', 'createdAt', 'customerComment'],
                'order_line_item' => ['id', 'parentId', 'label', 'quantity', 'totalPrice', 'type', 'payload'],
                'order_address' => ['id', 'firstName', 'lastName', 'street', 'zipcode', 'city', 'phoneNumber', 'additionalAddressLine1'],
                'state_machine_state' => ['technicalName'],
                'calculated_price' => ['totalPrice'],
                'shipping_method' => ['name', 'customFields', 'translated'],
            ],
            'associations' => [
                'shippingMethod' => [],
                'order' => [
                    'associations' => ['lineItems' => [], 'stateMachineState' => []],
                ],
                'shippingOrderAddress' => [],
            ],
        ];

        if (null !== $this->state) {
            $body['filter'][] = [
                'type' => 'equals',
                'field' => 'order.stateMachineState.technicalName',
                'value' => $this->state->value,
            ];
        }

        if (null !== $this->orderNumber) {
            $body['filter'][] = [
                'type' => 'equals',
                'field' => 'order.orderNumber',
                'value' => $this->orderNumber,
            ];
        }

        return $body;
    }
}
