<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Symfony\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Command\PrintOrderCommand;
use Veliu\OrderPrinter\Infra\Symfony\Messenger\PrintOrderFailedListener;
use Veliu\OrderPrinter\Tests\Support\SpyLogger;

#[CoversClass(PrintOrderFailedListener::class)]
final class PrintOrderFailedListenerTest extends TestCase
{
    private SpyLogger $logger;
    private PrintOrderFailedListener $listener;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
        $this->listener = new PrintOrderFailedListener($this->logger);
    }

    public function testPermanentFailureLogsErrorWithOrderNumber(): void
    {
        $envelope = new Envelope(new PrintOrderCommand('10556', true), [new RedeliveryStamp(10)]);
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('Cannot connect to printer "tcp://10.0.0.5:9100"'));

        ($this->listener)($event);

        $errors = $this->logger->recordsOfLevel('error');
        self::assertCount(1, $errors);
        self::assertSame('10556', $errors[0]['context']['orderNumber']);
        self::assertSame(11, $errors[0]['context']['attempts']);
        self::assertStringContainsString('Cannot connect to printer', $errors[0]['context']['error']);
        self::assertStringContainsString('{orderNumber}', $errors[0]['message']);
    }

    public function testRetriableFailureOnlyWarns(): void
    {
        $envelope = new Envelope(new PrintOrderCommand('10556', true));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('down'));
        $event->setForRetry();

        ($this->listener)($event);

        self::assertCount(0, $this->logger->recordsOfLevel('error'));
        $warnings = $this->logger->recordsOfLevel('warning');
        self::assertCount(1, $warnings);
        self::assertSame('10556', $warnings[0]['context']['orderNumber']);
        self::assertSame(1, $warnings[0]['context']['attempts']);
    }

    public function testIgnoresOtherMessages(): void
    {
        $event = new WorkerMessageFailedEvent(new Envelope(new PrintOpenOrdersCommand(true)), 'async', new \RuntimeException('down'));

        ($this->listener)($event);

        self::assertSame([], $this->logger->records);
    }
}
