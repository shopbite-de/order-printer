<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptArchiveInterface;

/** @psalm-api */
#[AsMessageHandler]
final readonly class PurgeReceiptsHandler
{
    public function __construct(
        private ReceiptArchiveInterface $receiptArchive,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeReceiptsCommand $command): void
    {
        $before = $this->clock->now()->modify(sprintf('-%d days', $command->retentionDays));
        $deleted = $this->receiptArchive->purgeOlderThan($before);

        $this->logger->info(sprintf('Deleted %d receipt copies older than %d days.', $deleted, $command->retentionDays), [
            'deleted' => $deleted,
            'retentionDays' => $command->retentionDays,
        ]);
    }
}
