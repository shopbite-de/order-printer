<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJob;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJobRepositoryInterface;

/** @psalm-api */
final readonly class PrintJobRepository implements PrintJobRepositoryInterface
{
    /**
     * A job that is still marked pending after this long is considered stale (for example the
     * worker died mid-job) and the order may be dispatched again. Must exceed the total retry
     * window of the async transport plus the Doctrine transport redeliver timeout (1 hour).
     */
    public const string PENDING_TTL = 'PT2H';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function isPending(string $orderNumber): bool
    {
        $job = $this->find($orderNumber);
        if (null === $job) {
            return false;
        }

        $staleBefore = $this->clock->now()->sub(new \DateInterval(self::PENDING_TTL));

        return $job->getDispatchedAt() > $staleBefore;
    }

    #[\Override]
    public function start(string $orderNumber): void
    {
        $now = $this->clock->now();

        if ($job = $this->find($orderNumber)) {
            $job->redispatch($now);
        } else {
            $this->entityManager->persist(new PrintJob($orderNumber, $now));
        }

        $this->entityManager->flush();
    }

    #[\Override]
    public function finish(string $orderNumber): void
    {
        if ($job = $this->find($orderNumber)) {
            $this->entityManager->remove($job);
            $this->entityManager->flush();
        }
    }

    /**
     * The scheduler worker and the print worker share this table from different processes,
     * so the identity map is cleared before every lookup to always read the current row.
     */
    private function find(string $orderNumber): ?PrintJob
    {
        $this->entityManager->clear();

        return $this->entityManager->find(PrintJob::class, $orderNumber);
    }
}
