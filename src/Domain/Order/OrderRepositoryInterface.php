<?php

namespace Veliu\OrderPrinter\Domain\Order;

use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;

/**
 * @psalm-api
 */
interface OrderRepositoryInterface
{
    /**
     * @psalm-param non-empty-string $number
     *
     * @throws OrderNotFound
     */
    public function getByOrderNumber(string $number): Order;

    /**
     * @return string[]
     *
     * @psalm-return list<non-empty-string>
     */
    public function findNewNumbers(): array;

    public function markInProgress(Order $order): void;

    /**
     * Orders the backoffice asked to print again ("Jetzt drucken") that are not printed since.
     *
     * @psalm-return list<ReprintRequest>
     */
    public function findReprintRequests(): array;

    /** Stores when the receipt of the order was printed (custom field shopbite_printed_at). */
    public function markPrinted(Order $order): void;
}
