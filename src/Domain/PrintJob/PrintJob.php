<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\PrintJob;

use Doctrine\ORM\Mapping as ORM;

/**
 * An order whose print job is queued or being retried.
 *
 * The scheduler poll skips orders with a pending job so a printer outage does not
 * queue the same order every 10 seconds. The row is removed once the receipt was
 * printed or the job failed permanently.
 *
 * @psalm-api
 */
#[ORM\Entity]
#[ORM\Table(name: 'print_job')]
class PrintJob
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $orderNumber;

    #[ORM\Column]
    private \DateTimeImmutable $dispatchedAt;

    /** @psalm-param non-empty-string $orderNumber */
    public function __construct(string $orderNumber, \DateTimeImmutable $dispatchedAt)
    {
        $this->orderNumber = $orderNumber;
        $this->dispatchedAt = $dispatchedAt;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getDispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function redispatch(\DateTimeImmutable $at): void
    {
        $this->dispatchedAt = $at;
    }
}
