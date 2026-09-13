<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Support;

use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;

/**
 * Stands in for Shopware: holds a single order and tracks its "open" state.
 */
final class InMemoryOrderRepository implements OrderRepositoryInterface
{
    public int $markInProgressCalls = 0;
    private bool $open;

    public function __construct(private readonly Order $order)
    {
        $this->open = $order->isNew;
    }

    #[\Override]
    public function getByOrderNumber(string $number): Order
    {
        if ($number !== $this->order->number) {
            throw new OrderNotFound($number);
        }

        return $this->current();
    }

    #[\Override]
    public function findNewNumbers(): array
    {
        return $this->open ? [$this->order->number] : [];
    }

    #[\Override]
    public function markInProgress(Order $order): void
    {
        ++$this->markInProgressCalls;
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    private function current(): Order
    {
        return new Order(
            $this->order->identifier,
            $this->order->number,
            $this->order->customerComment,
            $this->order->totalPrice,
            $this->order->shippingCost,
            $this->order->address,
            $this->order->items,
            $this->open,
            $this->order->shippingMethodName,
            $this->order->createdAt,
        );
    }
}
