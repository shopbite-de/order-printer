<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

use Mike42\Escpos\Printer;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prints a test receipt (shop name, time, container hostname, printer DSN) through the same
 * connector path as real orders, so printer:test verifies the whole chain
 * server → network → printer without a real order.
 *
 * @psalm-api
 */
final readonly class TestReceiptPrinter
{
    private const int WIDTH = 42;

    public function __construct(
        #[Autowire(env: 'PRINTER_DSN')]
        private string $printerDsn,
        #[Autowire(env: 'default::SHOP_NAME')]
        private ?string $shopName,
        #[Autowire(env: 'SHOPWARE_HOST')]
        private string $shopwareHost,
        private PrintConnectorFactory $connectorFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $dsn overrides PRINTER_DSN
     *
     * @throws \InvalidArgumentException when the DSN is malformed
     * @throws \RuntimeException         when the printer is not reachable
     * @throws \Exception                when the connector cannot be opened
     */
    public function print(?string $dsn = null): TestReceipt
    {
        $receipt = new TestReceipt(
            $this->resolveShopName(),
            $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin')),
            gethostname() ?: 'unknown',
            $dsn ?? $this->printerDsn,
        );

        $printer = new Printer($this->connectorFactory->create($receipt->printerDsn));

        try {
            $printer->initialize();
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setTextSize(2, 2);
            $printer->text("TESTDRUCK\n");
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->setTextSize(1, 2);
            $printer->text($this->divider());
            $printer->text(sprintf("%s\n", $receipt->shopName));
            $printer->text(sprintf("Zeit:    %s\n", $receipt->printedAt->format('d.m.Y H:i:s')));
            $printer->text(sprintf("Host:    %s\n", $receipt->hostname));
            $printer->text(sprintf("Drucker: %s\n", $receipt->printerDsn));
            $printer->text($this->divider());
            $printer->text("Wenn Sie diesen Bon lesen koennen, ist der\nDrucker korrekt angeschlossen.\n");
            $printer->feed(2);
            $printer->cut(Printer::CUT_PARTIAL);
        } finally {
            $printer->close();
        }

        return $receipt;
    }

    private function resolveShopName(): string
    {
        if (null !== $this->shopName && '' !== $this->shopName) {
            return $this->shopName;
        }

        $host = parse_url($this->shopwareHost, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? $host : $this->shopwareHost;
    }

    private function divider(): string
    {
        return str_repeat('-', self::WIDTH)."\n";
    }
}
