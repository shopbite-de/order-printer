<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Command;

/**
 * Poll for orders in state "open" and queue a print job for each one.
 *
 * Deliberately not routed to a transport: the scheduler worker (and the console command)
 * handle it synchronously. A failed poll is simply repeated on the next tick instead of
 * being retried and piling up behind the print jobs.
 */
final readonly class PrintOpenOrdersCommand
{
    public function __construct(
        public bool $markInProgress,
    ) {
    }
}
