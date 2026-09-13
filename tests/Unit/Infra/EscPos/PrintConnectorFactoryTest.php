<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\EscPos;

use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\EscPos\PrintConnectorFactory;

#[CoversClass(PrintConnectorFactory::class)]
final class PrintConnectorFactoryTest extends TestCase
{
    public function testDummySchemeReturnsDummyConnector(): void
    {
        $connector = new PrintConnectorFactory()->create('dummy://');

        $this->assertInstanceOf(DummyPrintConnector::class, $connector);
        $connector->finalize();
    }

    public function testFileSchemeWritesToGivenPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'printer');

        $connector = new PrintConnectorFactory()->create('file://'.$path);
        $connector->write("hello\n");
        $connector->finalize();

        $this->assertInstanceOf(FilePrintConnector::class, $connector);
        $this->assertSame("hello\n", file_get_contents($path));

        unlink($path);
    }

    public function testFileSchemeAcceptsPhpStreamWrappers(): void
    {
        $connector = new PrintConnectorFactory()->create('file://php://memory');

        $this->assertInstanceOf(FilePrintConnector::class, $connector);
        $connector->finalize();
    }

    public function testTcpSchemeConnectsToHostAndPort(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = (int) substr(stream_socket_get_name($server, false), strrpos(stream_socket_get_name($server, false), ':') + 1);

        $connector = new PrintConnectorFactory()->create(sprintf('tcp://127.0.0.1:%d', $port));
        $this->assertInstanceOf(NetworkPrintConnector::class, $connector);

        $connector->write("hello\n");
        $connector->finalize();

        $client = stream_socket_accept($server, 1);
        $this->assertNotFalse($client);
        $this->assertSame("hello\n", fread($client, 64));

        fclose($client);
        fclose($server);
    }

    public function testTcpSchemeFailsFastWhenHostIsUnreachable(): void
    {
        $factory = new PrintConnectorFactory(connectTimeout: 1);
        $start = microtime(true);

        try {
            $factory->create('tcp://10.255.255.1:9100');
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tcp://10.255.255.1:9100', $e->getMessage());
        }

        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDsnProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'bare name' => ['TM-T20'];
        yield 'unsupported scheme' => ['smb://server/printer'];
        yield 'file without path' => ['file://'];
        yield 'tcp without host' => ['tcp://'];
        yield 'tcp with port only' => ['tcp://:9100'];
    }

    #[DataProvider('invalidDsnProvider')]
    public function testInvalidDsnThrows(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PrintConnectorFactory()->create($dsn);
    }
}
