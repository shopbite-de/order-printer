<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptPositionPrintTypeEnum;

use function Psl\Type\float;
use function Psl\Type\literal_scalar;
use function Psl\Type\non_empty_string;
use function Psl\Type\nullable;
use function Psl\Type\optional;
use function Psl\Type\positive_int;
use function Psl\Type\shape;
use function Psl\Type\uint;
use function Psl\Type\union;

final readonly class OrderLineItem
{
    /**
     * @psalm-param non-empty-string $id
     * @psalm-param non-empty-string|null $parentId
     * @psalm-param non-empty-string $label
     * @psalm-param positive-int $quantity
     * @psalm-param non-empty-string $type
     * @psalm-param non-empty-string $productNumber
     */
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $label,
        public int $quantity,
        public float $totalPrice,
        public string $type,
        public string $productNumber,
        public ?ReceiptPositionPrintTypeEnum $printType,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self(
            $data['id'],
            $data['parentId'] ?? null,
            $data['label'],
            $data['quantity'],
            (float) $data['totalPrice'],
            $data['type'],
            $data['payload']['productNumber'],
            ReceiptPositionPrintTypeEnum::tryFrom($data['payload']['shopbite_receipt_print_type'] ?? '')
        );
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'id' => non_empty_string(),
            'parentId' => nullable(non_empty_string()),
            'label' => non_empty_string(),
            'quantity' => positive_int(),
            'totalPrice' => union(float(), uint()),
            'type' => union(literal_scalar('product'), literal_scalar('container')),
            'payload' => shape([
                'productNumber' => non_empty_string(),
                'shopbite_receipt_print_type' => optional(nullable(
                    union(literal_scalar('number'), literal_scalar('label'))
                )),
            ], allowUnknownFields: true),
        ], allowUnknownFields: true);
    }
}
