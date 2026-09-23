<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Veliu\OrderPrinter\Domain\Order\Exception\OrderNotFound;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Service\PrintOrderProcessorInterface;

/** @psalm-api */
#[AsMessageHandler]
final readonly class PrintOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PrintOrderProcessorInterface $printOrderProcessor,
        private LoggerInterface $logger = new NullLogger(),
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

        // Throws when the printer is unreachable: the message is retried by Messenger and the
        // order stays "open" in Shopware, because the processor marks it only after printing.
        ($this->printOrderProcessor)($order, $command->markInProgress);

        // Monitoring counts these lines to notice an evening without a single receipt.
        $this->logger->notice('Order {orderNumber} printed.', ['orderNumber' => $command->orderNumber]);
    }
}
