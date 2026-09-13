<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

/** What was printed by printer:test. */
final readonly class TestReceipt
{
    public function __construct(
        public string $shopName,
        public \DateTimeImmutable $printedAt,
        public string $hostname,
        public string $printerDsn,
    ) {
    }
}
