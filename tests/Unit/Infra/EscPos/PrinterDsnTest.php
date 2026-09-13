<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\EscPos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\EscPos\PrinterDsn;
use Veliu\OrderPrinter\Infra\EscPos\PrinterScheme;

#[CoversClass(PrinterDsn::class)]
final class PrinterDsnTest extends TestCase
{
    public function testDummy(): void
    {
        $dsn = PrinterDsn::parse('dummy://');

        self::assertSame(PrinterScheme::Dummy, $dsn->scheme);
        self::assertNull($dsn->path);
        self::assertNull($dsn->host);
    }

    public function testFileKeepsTheFullPath(): void
    {
        self::assertSame('/dev/usb/lp0', PrinterDsn::parse('file:///dev/usb/lp0')->path);
        self::assertSame('php://memory', PrinterDsn::parse('file://php://memory')->path);
        self::assertSame(PrinterScheme::File, PrinterDsn::parse('file:///dev/null')->scheme);
    }

    public function testTcpDefaultsToPort9100(): void
    {
        $dsn = PrinterDsn::parse('tcp://192.168.1.50');

        self::assertSame(PrinterScheme::Tcp, $dsn->scheme);
        self::assertSame('192.168.1.50', $dsn->host);
        self::assertSame(9100, $dsn->port);
        self::assertSame('tcp://192.168.1.50', $dsn->dsn);
    }

    public function testTcpWithExplicitPort(): void
    {
        $dsn = PrinterDsn::parse('tcp://printer.local:9200');

        self::assertSame('printer.local', $dsn->host);
        self::assertSame(9200, $dsn->port);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDsnProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'bare path' => ['/dev/usb/lp0'];
        yield 'unsupported scheme' => ['smb://server/printer'];
        yield 'file without path' => ['file://'];
        yield 'tcp without host' => ['tcp://'];
        yield 'tcp with port only' => ['tcp://:9100'];
    }

    #[DataProvider('invalidDsnProvider')]
    public function testInvalidDsnThrows(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PrinterDsn::parse($dsn);
    }
}
