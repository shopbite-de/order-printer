<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

/**
 * Delete archived receipt copies older than the retention period.
 *
 * Receipt copies contain personal data (name, address, phone), so they are not kept longer
 * than needed. Like PrintOpenOrdersCommand this is not routed to a transport: the scheduler
 * worker handles it inline, once a day.
 */
final readonly class PurgeReceiptsCommand
{
    /**
     * @psalm-param positive-int $retentionDays
     */
    public function __construct(
        public int $retentionDays,
    ) {
    }
}
