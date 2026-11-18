<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\Order;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Domain\Api\ApiResponseInterface;
use Veliu\OrderPrinter\Domain\Api\ResponseInterface;

use function Psl\Type\non_empty_string;
use function Psl\Type\shape;
use function Psl\Type\uint;
use function Psl\Type\vec;

/**
 * @implements ResponseInterface<FindOrderNumbersResponse>
 */
final readonly class FindOrderNumbersResponse implements ResponseInterface
{
    /**
     * @param string[] $orderNumbers
     *
     * @psalm-param list<non-empty-string> $orderNumbers
     */
    public function __construct(
        public array $orderNumbers,
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

        return new self(array_map(fn (array $order) => $order['orderNumber'], $data['data']));
    }

    #[\Override]
    public static function type(): TypeInterface
    {
        return shape([
            'total' => uint(),
            'data' => vec(shape([
                'orderNumber' => non_empty_string(),
            ], allowUnknownFields: true)),
        ], allowUnknownFields: true);
    }
}
