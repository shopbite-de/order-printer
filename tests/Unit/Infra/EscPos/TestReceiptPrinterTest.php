<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\EscPos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Veliu\OrderPrinter\Infra\EscPos\PrintConnectorFactory;
use Veliu\OrderPrinter\Infra\EscPos\TestReceiptPrinter;

#[CoversClass(TestReceiptPrinter::class)]
final class TestReceiptPrinterTest extends TestCase
{
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'testreceipt');
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testPrintsShopNameTimeHostAndDsn(): void
    {
        $printer = new TestReceiptPrinter(
            'file://'.$this->file,
            'Masala Mio',
            'https://shopware.example.com',
            new PrintConnectorFactory(),
            new MockClock('2026-09-13 12:00:00', 'UTC'),
        );

        $receipt = $printer->print();

        self::assertSame('Masala Mio', $receipt->shopName);
        self::assertSame('13.09.2026 14:00:00', $receipt->printedAt->format('d.m.Y H:i:s'), 'Europe/Berlin');
        self::assertSame(gethostname(), $receipt->hostname);
        self::assertSame('file://'.$this->file, $receipt->printerDsn);

        $bytes = file_get_contents($this->file);
        self::assertStringContainsString("\x1B\x40", $bytes, 'initialize');
        self::assertStringContainsString('TESTDRUCK', $bytes);
        self::assertStringContainsString('Masala Mio', $bytes);
        self::assertStringContainsString('Zeit:    13.09.2026 14:00:00', $bytes);
        self::assertStringContainsString('Host:    '.gethostname(), $bytes);
        self::assertStringContainsString('Drucker: file://'.$this->file, $bytes);
        self::assertStringContainsString("\x1D\x56", $bytes, 'cut');
    }

    public function testShopNameFallsBackToTheShopwareHost(): void
    {
        $printer = new TestReceiptPrinter('dummy://', null, 'https://shopware.shopbite.de/', new PrintConnectorFactory(), new MockClock());

        self::assertSame('shopware.shopbite.de', $printer->print()->shopName);
    }

    public function testDsnArgumentOverridesTheConfiguredPrinter(): void
    {
        $printer = new TestReceiptPrinter('tcp://10.255.255.1:9100', '', 'https://shopware.shopbite.de', new PrintConnectorFactory(), new MockClock());

        $receipt = $printer->print('file://'.$this->file);

        self::assertSame('file://'.$this->file, $receipt->printerDsn);
        self::assertSame('shopware.shopbite.de', $receipt->shopName, 'empty SHOP_NAME counts as unset');
        self::assertStringContainsString('TESTDRUCK', file_get_contents($this->file));
    }

    public function testUnreachablePrinterThrows(): void
    {
        $printer = new TestReceiptPrinter('tcp://10.255.255.1:9100', null, 'https://x', new PrintConnectorFactory(connectTimeout: 1), new MockClock());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to printer "tcp://10.255.255.1:9100"');

        $printer->print();
    }
}
