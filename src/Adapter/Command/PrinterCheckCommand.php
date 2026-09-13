<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Adapter\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Veliu\OrderPrinter\Infra\EscPos\PrinterChecker;

/** @psalm-api */
#[AsCommand('printer:check', description: 'Checks that the printer is reachable without printing anything (exit code 0/1, for health checks)')]
final readonly class PrinterCheckCommand
{
    public function __construct(
        #[Autowire(env: 'PRINTER_DSN')]
        private string $printerDsn,
        private PrinterChecker $checker,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Printer DSN to check instead of PRINTER_DSN, e.g. tcp://100.64.0.5:9100')]
        ?string $dsn = null,
    ): int {
        $dsn ??= $this->printerDsn;

        try {
            $this->checker->check($dsn);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Printer "%s" is reachable.', $dsn));

        return Command::SUCCESS;
    }
}
