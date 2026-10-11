<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api\Order;

use Psl\Type\TypeInterface;
use Veliu\OrderPrinter\Domain\Api\ApiResponseInterface;
use Veliu\OrderPrinter\Domain\Api\ResponseInterface;
use Veliu\OrderPrinter\Domain\Order\ReprintRequest;

use function Psl\Type\dict;
use function Psl\Type\mixed;
use function Psl\Type\non_empty_string;
use function Psl\Type\nullable;
use function Psl\Type\optional;
use function Psl\Type\shape;
use function Psl\Type\string;
use function Psl\Type\vec;

/**
 * @implements ResponseInterface<FindReprintRequestsResponse>
 */
final readonly class FindReprintRequestsResponse implements ResponseInterface
{
    /**
     * @psalm-param list<ReprintRequest> $requests
     */
    public function __construct(
        public array $requests,
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
        $requests = [];
        foreach ($data['data'] as $order) {
            $fields = $order['customFields'] ?? [];
            $requested = self::time($fields['shopbite_print_requested_at'] ?? null);
            if (null === $requested) {
                continue;
            }
            $printed = self::time($fields['shopbite_printed_at'] ?? null);
            if (null !== $printed && $printed >= $requested) {
                continue;
            }
            /** @var non-empty-string $requestedAt */
            $requestedAt = $fields['shopbite_print_requested_at'];
            $requests[] = new ReprintRequest($order['orderNumber'], $requestedAt);
        }

        return new self($requests);
    }

    #[\Override]
    public static function type(): TypeInterface
    {
        return shape([
            'data' => vec(shape([
                'orderNumber' => non_empty_string(),
                'customFields' => optional(nullable(dict(string(), mixed()))),
            ], allow_unknown_fields: true)),
        ], allow_unknown_fields: true);
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
