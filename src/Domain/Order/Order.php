<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Order;

use Veliu\OrderPrinter\Domain\Address\Address;

final readonly class Order
{
    /**
     * @param OrderItem[] $items
     *
     * @psalm-param non-empty-string $identifier
     * @psalm-param non-empty-string $number
     * @psalm-param non-empty-string|null $customerComment
     * @psalm-param non-empty-string $totalPrice
     * @psalm-param non-empty-string $shippingCost
     * @psalm-param non-empty-list<OrderItem> $items
     * @psalm-param non-empty-string $shippingMethodName
     */
    public function __construct(
        public string $identifier,
        public string $number,
        public ?string $customerComment,
        public string $totalPrice,
        public string $shippingCost,
        public Address $address,
        public array $items,
        public bool $isNew,
        public string $shippingMethodName,
        public \DateTimeImmutable $createdAt,
        // pickup in the restaurant: the receipt has no delivery address
        public bool $isPickup = false,
    ) {
    }
}
