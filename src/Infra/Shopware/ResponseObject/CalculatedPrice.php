<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;

use function Psl\Type\float;
use function Psl\Type\shape;
use function Psl\Type\uint;
use function Psl\Type\union;

final readonly class CalculatedPrice
{
    public function __construct(
        public float $totalPrice,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self((float) $data['totalPrice']);
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'totalPrice' => union(float(), uint()),
        ], allowUnknownFields: true);
    }
}
