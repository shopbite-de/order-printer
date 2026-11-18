<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Domain\Api\ApiResponseInterface;
use Veliu\OrderPrinter\Domain\Api\ResponseInterface;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\OrderDelivery;

use function Psl\Type\shape;
use function Psl\Type\uint;
use function Psl\Type\vec;

/**
 * @implements ResponseInterface<FindDeliveriesResponse>
 */
final readonly class FindDeliveriesResponse implements ResponseInterface
{
    /**
     * @param OrderDelivery[] $deliveries
     *
     * @psalm-param list<OrderDelivery> $deliveries
     */
    public function __construct(
        public array $deliveries,
    ) {
    }

    #[\Override]
    public static function fromResponse(ApiResponseInterface $response): self
    {
        return self::fromArray($response->getBody());
    }

    #[\Override]
    public static function fromArray(array $array): self
    {
        $data = self::type()->coerce($array);

        return new self(array_map([OrderDelivery::class, 'fromArray'], $data['data']));
    }

    #[\Override]
    public static function type(): TypeInterface
    {
        return shape([
            'total' => uint(),
            'data' => vec(OrderDelivery::arrayShape()),
        ], allowUnknownFields: true);
    }

    public function first(): ?OrderDelivery
    {
        return $this->deliveries[0] ?? null;
    }
}
