<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PrinterCommandsTest extends KernelTestCase
{
    private Application $application;
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
        $this->file = tempnam(sys_get_temp_dir(), 'testreceipt');
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testCheckSucceedsForTheConfiguredPrinter(): void
    {
        // PRINTER_DSN=file:///dev/null from .env
        $tester = $this->execute('printer:check');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Printer "file:///dev/null" is reachable', $tester->getDisplay());
    }

    public function testCheckSucceedsForDummy(): void
    {
        $tester = $this->execute('printer:check', ['--dsn' => 'dummy://']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testCheckFailsFastForAnUnreachableHost(): void
    {
        $start = microtime(true);
        $tester = $this->execute('printer:check', ['--dsn' => 'tcp://10.255.255.1:9100']);

        self::assertLessThan(5.0, microtime(true) - $start, 'must answer in under 5 seconds');
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot connect to printer "tcp://10.255.255.1:9100"', $this->flatten($tester->getDisplay()));
    }

    public function testCheckFailsForAMissingDevice(): void
    {
        $tester = $this->execute('printer:check', ['--dsn' => 'file:///dev/usb/lp99-missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testTestPrintsAReceipt(): void
    {
        $tester = $this->execute('printer:test', ['--dsn' => 'file://'.$this->file]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Test receipt sent to', $tester->getDisplay());
        self::assertStringContainsString('TESTDRUCK', file_get_contents($this->file));
        self::assertStringContainsString('myshopwareshop.com', file_get_contents($this->file), 'shop name from SHOPWARE_HOST in .env');
    }

    public function testTestSucceedsForDummy(): void
    {
        $tester = $this->execute('printer:test', ['--dsn' => 'dummy://']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testTestFailsWithReadableMessageWhenThePrinterIsUnreachable(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($server);

        $tester = $this->execute('printer:test', ['--dsn' => sprintf('tcp://127.0.0.1:%d', $port)]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Cannot connect to printer "tcp://127.0.0.1:%d"', $port), $this->flatten($tester->getDisplay()));
    }

    public function testTestFailsForAMalformedDsn(): void
    {
        $tester = $this->execute('printer:test', ['--dsn' => 'TM-T20']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unsupported printer DSN', $tester->getDisplay());
    }

    /** @param array<string, string> $input */
    private function execute(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input);

        return $tester;
    }

    /** SymfonyStyle wraps long messages; join the lines for substring assertions. */
    private function flatten(string $display): string
    {
        return preg_replace('/\s+/', ' ', $display);
    }
}
