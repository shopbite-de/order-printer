<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Address;

final readonly class Address
{
    public function __construct(
        public string $name,
        public string $street,
        public string $city,
        public ?string $phone,
        public ?string $additional,
    ) {
    }
}
