<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\PrintJob;

/**
 * Tracks which orders currently have a print job queued or retrying.
 *
 * @psalm-api
 */
interface PrintJobRepositoryInterface
{
    /**
     * Whether a print job for the order is queued or being retried.
     * Jobs older than the retry window count as stale and are not pending anymore.
     *
     * @psalm-param non-empty-string $orderNumber
     */
    public function isPending(string $orderNumber): bool;

    /**
     * Records that a print job for the order was dispatched now.
     *
     * @psalm-param non-empty-string $orderNumber
     */
    public function start(string $orderNumber): void;

    /**
     * Releases the order: the receipt was printed, or the job failed permanently.
     *
     * @psalm-param non-empty-string $orderNumber
     */
    public function finish(string $orderNumber): void;
}
