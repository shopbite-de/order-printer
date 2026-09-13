<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Adapter\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Veliu\OrderPrinter\Infra\EscPos\TestReceiptPrinter;

/** @psalm-api */
#[AsCommand('printer:test', description: 'Prints a test receipt through the configured printer')]
final readonly class PrinterTestCommand
{
    public function __construct(
        private TestReceiptPrinter $testReceiptPrinter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Printer DSN to print to instead of PRINTER_DSN, e.g. tcp://100.64.0.5:9100')]
        ?string $dsn = null,
    ): int {
        try {
            $receipt = $this->testReceiptPrinter->print($dsn);
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Test receipt sent to "%s" (%s, %s, host %s).',
            $receipt->printerDsn,
            $receipt->shopName,
            $receipt->printedAt->format('d.m.Y H:i:s'),
            $receipt->hostname,
        ));

        return Command::SUCCESS;
    }
}
