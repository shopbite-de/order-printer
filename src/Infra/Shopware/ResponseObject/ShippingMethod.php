<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;

use function Psl\Type\non_empty_string;
use function Psl\Type\shape;

final readonly class ShippingMethod
{
    /** @psalm-param non-empty-string $name */
    public function __construct(
        public string $name,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self($data['name']);
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'name' => non_empty_string(),
        ], allowUnknownFields: true);
    }
}
