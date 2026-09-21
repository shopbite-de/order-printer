<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Receipt;

/**
 * The directory of receipt copies written next to every print.
 */
interface ReceiptArchiveInterface
{
    /**
     * Deletes every receipt copy last modified before the given point in time.
     *
     * @return int number of deleted copies
     */
    public function purgeOlderThan(\DateTimeImmutable $before): int;
}
