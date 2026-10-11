<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Order;

/**
 * "Jetzt drucken" from the backoffice: the order custom field shopbite_print_requested_at is
 * newer than shopbite_printed_at. The timestamp identifies the request, so one request prints
 * once and a new one prints again.
 */
final readonly class ReprintRequest
{
    /**
     * @psalm-param non-empty-string $orderNumber
     * @psalm-param non-empty-string $requestedAt
     */
    public function __construct(
        public string $orderNumber,
        public string $requestedAt,
    ) {
    }
}
