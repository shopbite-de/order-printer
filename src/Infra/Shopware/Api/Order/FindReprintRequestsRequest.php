<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\Order;

use Veliu\OrderPrinter\Domain\Api\HasNoAdditionalHeadersTrait;
use Veliu\OrderPrinter\Domain\Api\IsPostTrait;
use Veliu\OrderPrinter\Domain\Api\RequestInterface;

/**
 * Orders with a "Jetzt drucken" request (custom field shopbite_print_requested_at, set by the
 * backoffice) since the given time. Whether it is newer than shopbite_printed_at is decided in
 * the response, the DAL cannot compare two fields.
 */
final readonly class FindReprintRequestsRequest implements RequestInterface
{
    use IsPostTrait;
    use HasNoAdditionalHeadersTrait;

    public function __construct(
        public \DateTimeImmutable $since,
    ) {
    }

    #[\Override]
    public function getUri(): string
    {
        return '/api/search/order';
    }

    #[\Override]
    public function getBody(): ?array
    {
        return [
            'limit' => 50,
            'includes' => ['order' => ['orderNumber', 'customFields']],
            'filter' => [
                [
                    'type' => 'range',
                    'field' => 'customFields.shopbite_print_requested_at',
                    'parameters' => ['gte' => $this->since->format(\DateTimeInterface::ATOM)],
                ],
            ],
        ];
    }
}
