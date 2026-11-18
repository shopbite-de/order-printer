<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\ResponseObject;

use Psl\Type\TypeInterface;

use function Psl\Type\non_empty_string;
use function Psl\Type\nullable;
use function Psl\Type\shape;

final readonly class OrderAddress
{
    /**
     * @psalm-param non-empty-string $firstName
     * @psalm-param non-empty-string $lastName
     * @psalm-param non-empty-string $street
     * @psalm-param non-empty-string $city
     * @psalm-param non-empty-string|null $phoneNumber
     * @psalm-param non-empty-string|null $additionalAddressLine1
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $city,
        public ?string $phoneNumber,
        public ?string $additionalAddressLine1,
    ) {
    }

    public static function fromArray(array $array): self
    {
        $data = self::arrayShape()->coerce($array);

        return new self(
            $data['firstName'],
            $data['lastName'],
            $data['street'],
            $data['city'],
            $data['phoneNumber'],
            $data['additionalAddressLine1'],
        );
    }

    public static function arrayShape(): TypeInterface
    {
        return shape([
            'firstName' => non_empty_string(),
            'lastName' => non_empty_string(),
            'street' => non_empty_string(),
            'city' => non_empty_string(),
            'phoneNumber' => nullable(non_empty_string()),
            'additionalAddressLine1' => nullable(non_empty_string()),
        ], allowUnknownFields: true);
    }
}
