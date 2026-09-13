<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\EscPos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\EscPos\PrinterChecker;

#[CoversClass(PrinterChecker::class)]
final class PrinterCheckerTest extends TestCase
{
    private PrinterChecker $checker;

    #[\Override]
    protected function setUp(): void
    {
        $this->checker = new PrinterChecker(connectTimeout: 1);
    }

    public function testDummyIsAlwaysReachable(): void
    {
        $this->checker->check('dummy://');

        $this->addToAssertionCount(1);
    }

    public function testWritableDeviceIsReachable(): void
    {
        $this->checker->check('file:///dev/null');
        $this->checker->check('file://php://memory');

        $this->addToAssertionCount(2);
    }

    public function testMissingDeviceFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Printer device "/dev/usb/lp99-does-not-exist" does not exist.');

        $this->checker->check('file:///dev/usb/lp99-does-not-exist');
    }

    public function testUnwritableDeviceFails(): void
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('root can write anything');
        }

        $path = tempnam(sys_get_temp_dir(), 'printer');
        chmod($path, 0444);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('is not writable');

            $this->checker->check('file://'.$path);
        } finally {
            unlink($path);
        }
    }

    public function testListeningTcpPortIsReachableWithoutSendingAnything(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $this->checker->check(sprintf('tcp://127.0.0.1:%d', $port));

        $client = stream_socket_accept($server, 1);
        self::assertNotFalse($client, 'the checker connected');
        stream_set_timeout($client, 0, 200_000);
        self::assertSame('', (string) fread($client, 64), 'nothing was printed');

        fclose($client);
        fclose($server);
    }

    public function testClosedTcpPortFailsImmediately(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Cannot connect to printer "tcp://127.0.0.1:%d"', $port));

        $this->checker->check(sprintf('tcp://127.0.0.1:%d', $port));
    }

    public function testUnreachableHostFailsWithinTheTimeout(): void
    {
        $start = microtime(true);

        try {
            $this->checker->check('tcp://10.255.255.1:9100');
            self::fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot connect to printer "tcp://10.255.255.1:9100"', $e->getMessage());
        }

        self::assertLessThan(5.0, microtime(true) - $start);
    }

    public function testInvalidDsnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->checker->check('TM-T20');
    }
}
