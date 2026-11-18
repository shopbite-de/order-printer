<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;

use function Psl\Type\array_key;
use function Psl\Type\dict;
use function Psl\Type\float;
use function Psl\Type\mixed;
use function Psl\Type\non_empty_string;
use function Psl\Type\non_empty_vec;
use function Psl\Type\nullable;
use function Psl\Type\optional;
use function Psl\Type\shape;
use function Psl\Type\uint;
use function Psl\Type\union;

final readonly class Order
{
    /**
     * @param OrderLineItem[] $lineItems
     *
     * @psalm-param non-empty-string $id
     * @psalm-param non-empty-string $orderNumber
     * @psalm-param non-empty-string|null $customerComment
     * @psalm-param non-empty-list<OrderLineItem> $lineItems
     */
    public function __construct(
        public string $id,
        public string $orderNumber,
        public ?string $customerComment,
        public float $amountTotal,
        public array $lineItems,
        public StateMachineState $stateMachineState,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self(
            $data['id'],
            $data['orderNumber'],
            $data['customerComment'] ?? null,
            (float) $data['amountTotal'],
            array_map([OrderLineItem::class, 'fromArray'], $data['lineItems']),
            StateMachineState::fromArray($data['stateMachineState']),
            new \DateTimeImmutable($data['createdAt']),
        );
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'id' => non_empty_string(),
            'orderNumber' => non_empty_string(),
            'customerComment' => optional(nullable(non_empty_string())),
            'amountTotal' => union(float(), uint()),
            'lineItems' => non_empty_vec(dict(array_key(), mixed())),
            'stateMachineState' => dict(array_key(), mixed()),
        ], allowUnknownFields: true);
    }
}
