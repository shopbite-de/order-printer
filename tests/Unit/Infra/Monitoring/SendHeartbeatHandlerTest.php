<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Monitoring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Veliu\OrderPrinter\Infra\EscPos\PrinterChecker;
use Veliu\OrderPrinter\Infra\Monitoring\SendHeartbeat;
use Veliu\OrderPrinter\Infra\Monitoring\SendHeartbeatHandler;
use Veliu\OrderPrinter\Tests\Support\SpyLogger;

#[CoversClass(SendHeartbeatHandler::class)]
final class SendHeartbeatHandlerTest extends TestCase
{
    private const string URL = 'https://status.example.com/api/push/abc123';

    /** @var list<string> */
    private array $requests = [];
    private SpyLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    public function testPushesUpWhenThePrinterIsReachable(): void
    {
        $this->handler('dummy://')(new SendHeartbeat());

        self::assertCount(1, $this->requests);
        self::assertStringStartsWith(self::URL.'?status=up&msg=OK&ping=', $this->requests[0]);
        self::assertSame([], $this->logger->records);
    }

    public function testPushesDownWithTheReasonWhenThePortIsClosed(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        fclose($server);

        $this->handler('tcp://'.$name)(new SendHeartbeat());

        self::assertCount(1, $this->requests);
        parse_str((string) parse_url($this->requests[0], \PHP_URL_QUERY), $query);
        self::assertSame('down', $query['status']);
        self::assertIsString($query['msg']);
        self::assertStringStartsWith('Drucker aus oder abgesteckt', $query['msg']);
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
    }

    public function testReplacesTheQueryOfAUrlCopiedFromUptimeKuma(): void
    {
        $this->handler('dummy://', self::URL.'?status=up&msg=OK&ping=')(new SendHeartbeat());

        self::assertStringStartsWith(self::URL.'?status=up&msg=OK&ping=', $this->requests[0]);
        self::assertSame(1, substr_count($this->requests[0], 'status='));
    }

    public function testAnUnreachableMonitorIsLoggedNotThrown(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('Could not resolve host'));

        new SendHeartbeatHandler(self::URL, 'dummy://', new PrinterChecker(), $client, $this->logger)(new SendHeartbeat());

        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Heartbeat push failed: {error}', $this->logger->records[0]['message']);
    }

    public function testDoesNothingWithoutUrl(): void
    {
        $this->handler('dummy://', '')(new SendHeartbeat());

        self::assertSame([], $this->requests);
    }

    public function testDescribeTellsPrinterOffFromPiOffline(): void
    {
        self::assertStringStartsWith('Drucker aus', SendHeartbeatHandler::describe('Cannot connect to printer "tcp://x:9100": Connection refused'));
        self::assertStringStartsWith('Pi-Gateway nicht erreichbar', SendHeartbeatHandler::describe('Cannot connect to printer "tcp://x:9100": Connection timed out'));
        self::assertSame('Printer device "/dev/usb/lp0" does not exist.', SendHeartbeatHandler::describe('Printer device "/dev/usb/lp0" does not exist.'));
    }

    private function handler(string $dsn, string $url = self::URL): SendHeartbeatHandler
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;

            return new MockResponse('{"ok":true}');
        });

        return new SendHeartbeatHandler($url, $dsn, new PrinterChecker(), $client, $this->logger);
    }
}
