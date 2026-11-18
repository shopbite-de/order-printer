<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Infra\Shopware\OrderStateEnum;

use function Psl\Type\non_empty_string;
use function Psl\Type\shape;

final readonly class StateMachineState
{
    public function __construct(
        public OrderStateEnum $technicalName,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self(OrderStateEnum::fromTechnicalName($data['technicalName']));
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'technicalName' => non_empty_string(),
        ], allowUnknownFields: true);
    }
}
