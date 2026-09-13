<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJobRepositoryInterface;

/**
 * @psalm-api
 */
#[AsMessageHandler]
final readonly class PrintOpenOrdersHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PrintJobRepositoryInterface $printJobs,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(PrintOpenOrdersCommand $command): void
    {
        foreach ($this->orderRepository->findNewNumbers() as $orderNumber) {
            // Still queued or retrying from an earlier poll: do not queue it a second time.
            if ($this->printJobs->isPending($orderNumber)) {
                continue;
            }

            $this->printJobs->start($orderNumber);

            try {
                $this->messageBus->dispatch(new PrintOrderCommand($orderNumber, $command->markInProgress));
            } catch (\Throwable $e) {
                // Only reachable with a synchronous transport (dev): release the order so the
                // next poll tries again instead of waiting for the job to go stale.
                $this->printJobs->finish($orderNumber);

                throw $e;
            }
        }
    }
}
