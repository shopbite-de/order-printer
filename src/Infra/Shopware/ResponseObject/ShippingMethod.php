<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptText;

use function Psl\Type\array_key;
use function Psl\Type\dict;
use function Psl\Type\mixed;
use function Psl\Type\non_empty_string;
use function Psl\Type\nullable;
use function Psl\Type\optional;
use function Psl\Type\shape;

final readonly class ShippingMethod
{
    /** Custom field of the ShopBite plugin: "pickup" or "delivery". */
    private const string FULFILLMENT_FIELD = 'shopbite_fulfillment';

    /** @psalm-param non-empty-string $name */
    public function __construct(
        public string $name,
        public bool $isPickup,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        // only the plugin's field decides, a method without it delivers; `translated` holds the custom
        // fields merged over the language fallback, the raw ones only count when it is missing
        $customFields = $data['translated']['customFields'] ?? $data['customFields'] ?? [];
        $isPickup = 'pickup' === ($customFields[self::FULFILLMENT_FIELD] ?? null);

        return new self(ReceiptText::nonEmptyLine($data['name']), $isPickup);
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'name' => non_empty_string(),
            'customFields' => optional(nullable(dict(array_key(), mixed()))),
            'translated' => optional(shape([
                'customFields' => optional(nullable(dict(array_key(), mixed()))),
            ], allowUnknownFields: true)),
        ], allowUnknownFields: true);
    }
}
