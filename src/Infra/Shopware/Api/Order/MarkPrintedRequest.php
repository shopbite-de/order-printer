<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\Order;

use Veliu\OrderPrinter\Domain\Api\HasNoAdditionalHeadersTrait;
use Veliu\OrderPrinter\Domain\Api\RequestInterface;

/** Sets shopbite_printed_at on the order (Shopware merges custom fields on update). */
final readonly class MarkPrintedRequest implements RequestInterface
{
    use HasNoAdditionalHeadersTrait;

    /**
     * @psalm-param non-empty-string $orderId
     */
    public function __construct(
        public string $orderId,
        public \DateTimeImmutable $printedAt,
    ) {
    }

    #[\Override]
    public function getMethod(): string
    {
        return 'PATCH';
    }

    #[\Override]
    public function getUri(): string
    {
        return sprintf('/api/order/%s', $this->orderId);
    }

    #[\Override]
    public function getBody(): ?array
    {
        return [
            'customFields' => [
                'shopbite_printed_at' => $this->printedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ];
    }
}
