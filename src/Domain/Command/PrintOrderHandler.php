<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Service\PrintOrderProcessorInterface;

/** @psalm-api */
#[AsMessageHandler]
final readonly class PrintOrderHandler
{
    /**
     * A "Jetzt drucken" request prints at most once, even if storing the print time in Shopware
     * keeps failing (the request would then look pending to every poll). Longer than the 24 h
     * the poll looks back for requests.
     */
    public const float REPRINT_LOCK_TTL = 90000.0;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PrintOrderProcessorInterface $printOrderProcessor,
        private LoggerInterface $logger = new NullLogger(),
        private ?LockFactory $lockFactory = null,
    ) {
    }

    public function __invoke(PrintOrderCommand $command): void
    {
        try {
            $order = $this->orderRepository->getByOrderNumber($command->orderNumber);
        } catch (OrderNotFound $e) {
            // Retrying cannot make the order appear; fail immediately.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $reprintLock = null;
        if (null !== $command->reprintRequestedAt && null !== $this->lockFactory) {
            $reprintLock = $this->lockFactory->createLock(
                'reprinted-'.md5($command->orderNumber.$command->reprintRequestedAt),
                self::REPRINT_LOCK_TTL,
                autoRelease: false,
            );
            if (!$reprintLock->acquire()) {
                $this->logger->info('Reprint of order {orderNumber} already done.', ['orderNumber' => $command->orderNumber]);

                return;
            }
        }

        try {
            // Throws when the printer is unreachable: the message is retried by Messenger and the
            // order stays "open" in Shopware, because the processor marks it only after printing.
            ($this->printOrderProcessor)($order, $command->markInProgress);
        } catch (\Throwable $e) {
            // nothing came out: the retry may print
            $reprintLock?->release();

            throw $e;
        }

        // Monitoring counts these lines to notice an evening without a single receipt.
        $this->logger->notice('Order {orderNumber} printed.', [
            'orderNumber' => $command->orderNumber,
            'reprint' => null !== $command->reprintRequestedAt,
        ]);
    }
}
