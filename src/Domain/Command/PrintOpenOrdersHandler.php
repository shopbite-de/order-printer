<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;

/**
 * @psalm-api
 */
#[AsMessageHandler]
final readonly class PrintOpenOrdersHandler
{
    /**
     * Every PrintOrderCommand queued by the poll carries a DeduplicateStamp: Messenger's
     * DeduplicateMiddleware acquires a lock per order on dispatch and silently drops the
     * message while the lock is held, so a printer outage does not queue the same order every
     * 10 seconds. The lock is released after a successful print, or when Messenger gives up
     * retrying (ReleaseDeduplicationLockOnFailureListener), so the next poll queues the order
     * again as long as it is still open.
     *
     * The TTL only matters when a worker dies mid-job. It must exceed the retry window of the
     * async transport (~30 min) plus the Doctrine transport redeliver timeout (1 h).
     */
    public const float DEDUPLICATION_TTL = 7200.0;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private MessageBusInterface $messageBus,
        private LockFactory $lockFactory,
    ) {
    }

    public function __invoke(PrintOpenOrdersCommand $command): void
    {
        foreach ($this->orderRepository->findNewNumbers() as $orderNumber) {
            $stamp = new DeduplicateStamp(self::deduplicationKey($orderNumber), self::DEDUPLICATION_TTL);

            try {
                $this->messageBus->dispatch(new PrintOrderCommand($orderNumber, $command->markInProgress), [$stamp]);
            } catch (\Throwable $e) {
                // Only reachable with a synchronous transport (dev), where the print ran inline
                // and failed: release the lock so the next poll tries again instead of waiting
                // for it to expire.
                $this->releaseQuietly($stamp);

                throw $e;
            }
        }
    }

    /**
     * @psalm-param non-empty-string $orderNumber
     *
     * @psalm-return non-empty-string
     */
    public static function deduplicationKey(string $orderNumber): string
    {
        return 'print-order-'.$orderNumber;
    }

    private function releaseQuietly(DeduplicateStamp $stamp): void
    {
        try {
            $this->lockFactory->createLockFromKey($stamp->getKey())->release();
        } catch (LockReleasingException) {
            // Held by another process; nothing to release for this dispatch.
        }
    }
}
