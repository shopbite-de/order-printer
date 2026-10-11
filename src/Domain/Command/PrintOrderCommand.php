<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'async')]
final readonly class PrintOrderCommand
{
    /**
     * @psalm-param non-empty-string $orderNumber
     * @psalm-param non-empty-string|null $reprintRequestedAt set for "Jetzt drucken" from the backoffice
     */
    public function __construct(
        public string $orderNumber,
        public bool $markInProgress,
        public ?string $reprintRequestedAt = null,
    ) {
    }
}
