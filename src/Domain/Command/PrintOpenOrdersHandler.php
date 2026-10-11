<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Order\ReprintRequest;

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
        $openNumbers = $this->orderRepository->findNewNumbers();
        foreach ($openNumbers as $orderNumber) {
            $this->dispatch(
                new PrintOrderCommand($orderNumber, $command->markInProgress),
                new DeduplicateStamp(self::deduplicationKey($orderNumber), self::DEDUPLICATION_TTL),
            );
        }

        // "Jetzt drucken" from the backoffice. An open order is printed by the loop above anyway;
        // the key contains the request time, so each request prints exactly once.
        foreach ($this->orderRepository->findReprintRequests() as $request) {
            if (\in_array($request->orderNumber, $openNumbers, true)) {
                continue;
            }
            $this->dispatch(
                new PrintOrderCommand($request->orderNumber, $command->markInProgress, $request->requestedAt),
                new DeduplicateStamp(self::reprintDeduplicationKey($request), self::DEDUPLICATION_TTL),
            );
        }
    }

    private function dispatch(PrintOrderCommand $message, DeduplicateStamp $stamp): void
    {
        try {
            $this->messageBus->dispatch($message, [$stamp]);
        } catch (\Throwable $e) {
            // Only reachable with a synchronous transport (dev), where the print ran inline
            // and failed: release the lock so the next poll tries again instead of waiting
            // for it to expire.
            $this->releaseQuietly($stamp);

            throw $e;
        }
    }

    /**
     * @psalm-return non-empty-string
     */
    public static function reprintDeduplicationKey(ReprintRequest $request): string
    {
        return 'reprint-order-'.$request->orderNumber.'-'.md5($request->requestedAt);
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
