<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Symfony\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;

/**
 * Logs failed print jobs with their order number: a warning per retried attempt and an
 * error once Messenger gives up, so monitoring can alert on receipts that were not printed.
 *
 * Runs after Messenger's retry listener (priority 100) has decided whether to retry.
 *
 * @psalm-api
 */
#[AsEventListener]
final readonly class PrintOrderFailedListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof PrintOrderCommand) {
            return;
        }

        $retryCount = $event->getEnvelope()->last(RedeliveryStamp::class)?->getRetryCount() ?? 0;
        $context = [
            'orderNumber' => $message->orderNumber,
            'attempts' => $retryCount + 1,
            'error' => $event->getThrowable()->getMessage(),
            'exception' => $event->getThrowable(),
        ];

        if ($event->willRetry()) {
            $this->logger->warning('Printing order {orderNumber} failed (attempt {attempts}), will retry: {error}', $context);

            return;
        }

        $this->logger->error('Printing order {orderNumber} failed permanently after {attempts} attempt(s): {error}', $context);
    }
}
