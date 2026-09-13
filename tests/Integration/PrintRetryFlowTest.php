<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderItem;
use Veliu\OrderPrinter\Domain\PrintJob\PrintJobRepositoryInterface;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptPositionPrintTypeEnum;
use Veliu\OrderPrinter\Infra\Shopware\OrderRepository;
use Veliu\OrderPrinter\Infra\Symfony\Messenger\PrintOrderFailedListener;
use Veliu\OrderPrinter\Tests\Support\InMemoryOrderRepository;
use Veliu\OrderPrinter\Tests\Support\SpyLogger;

/**
 * End-to-end: scheduler poll → async queue → worker → real ESC/POS connector over TCP,
 * with the retry strategy from messenger.yaml and a MockClock so backoff delays cost no time.
 * "Printer unreachable" is a closed local TCP port; "printer back" opens a listener on it.
 */
final class PrintRetryFlowTest extends KernelTestCase
{
    private const string ORDER_NUMBER = '10556';
    private const string RECEIPT_DIR = __DIR__.'/../../var/tests/receipts/';

    private MockClock $clock;
    private SpyLogger $logger;
    private InMemoryOrderRepository $orders;
    private MessageBusInterface $bus;
    private InMemoryTransport $async;
    private InMemoryTransport $failed;
    private PrintJobRepositoryInterface $printJobs;
    private EventDispatcher $dispatcher;
    private int $printerPort;
    /** @var resource|null */
    private $printerServer = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->printerPort = self::reserveClosedPort();
        $_SERVER['PRINTER_DSN'] = $_ENV['PRINTER_DSN'] = sprintf('tcp://127.0.0.1:%d', $this->printerPort);

        self::bootKernel();
        $container = static::getContainer();

        $this->clock = new MockClock('2026-09-13 12:00:00');
        $container->set('clock', $this->clock);
        $this->logger = new SpyLogger();
        $container->set('logger', $this->logger);
        $this->orders = new InMemoryOrderRepository(self::createOpenOrder());
        $container->set(OrderRepository::class, $this->orders);

        $entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->bus = $container->get(MessageBusInterface::class);
        $this->async = $container->get('messenger.transport.async');
        $this->failed = $container->get('messenger.transport.failed');
        $this->printJobs = $container->get(PrintJobRepositoryInterface::class);

        // The same listeners messenger:consume uses, minus the ones that reset services or react to signals.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($container->get('messenger.retry.send_failed_message_for_retry_listener'));
        $dispatcher->addSubscriber($container->get('messenger.failure.add_error_details_stamp_listener'));
        $dispatcher->addSubscriber($container->get('messenger.failure.send_failed_message_to_failure_transport_listener'));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $container->get(PrintOrderFailedListener::class));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $idleTicks = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$idleTicks): void {
            if (!$event->isWorkerIdle()) {
                $idleTicks = 0;
            } elseif (++$idleTicks > 1000) {
                $event->getWorker()->stop();
                self::fail('Worker idled for more than 1000 ticks: the retried message never became available.');
            }
        });
        $this->dispatcher = $dispatcher;

        self::cleanReceiptDir();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->stopPrinter();
        self::cleanReceiptDir();
        unset($_SERVER['PRINTER_DSN'], $_ENV['PRINTER_DSN']);

        parent::tearDown();
    }

    public function testRetryStrategyBacksOffFrom10sTo5minAndGivesUpAfter10Retries(): void
    {
        $strategy = static::getContainer()->get('messenger.retry_strategy_locator')->get('async');
        $envelope = static fn (int $retries) => new Envelope(new \stdClass(), $retries ? [new RedeliveryStamp($retries)] : []);

        $delays = [];
        for ($retry = 0; $retry < 10; ++$retry) {
            self::assertTrue($strategy->isRetryable($envelope($retry)));
            $delays[] = $strategy->getWaitingTime($envelope($retry));
        }

        self::assertSame([10_000, 20_000, 40_000, 80_000, 160_000, 300_000, 300_000, 300_000, 300_000, 300_000], $delays);
        self::assertFalse($strategy->isRetryable($envelope(10)));
        self::assertSame(1810, array_sum($delays) / 1000, 'total retry window in seconds');
    }

    public function testReceiptIsPrintedExactlyOnceAfterThePrinterBecomesReachable(): void
    {
        $this->poll();
        self::assertCount(1, $this->async->getSent(), 'the open order is queued once');
        self::assertTrue($this->printJobs->isPending(self::ORDER_NUMBER));

        $this->poll();
        self::assertCount(1, $this->async->getSent(), 'a second poll does not queue the pending order again');

        // Attempt 1: nothing listens on the printer port.
        $this->runWorkerOnce();
        self::assertSame(0, $this->orders->markInProgressCalls);
        self::assertTrue($this->orders->isOpen(), 'a failed print leaves the order open in Shopware');
        self::assertTrue($this->printJobs->isPending(self::ORDER_NUMBER));
        self::assertRetryQueued(retryCount: 1, delayMs: 10_000);
        self::assertCount(0, glob(self::RECEIPT_DIR.'*'), 'no receipt copy without a successful print');

        $this->poll();
        self::assertCount(2, $this->async->getSent(), 'polling while a retry is pending queues nothing');

        // Attempt 2: still down. The worker waits out the 10s delay on the mock clock.
        $before = $this->clock->now();
        $this->runWorkerOnce();
        self::assertGreaterThanOrEqual(10, $this->clock->now()->getTimestamp() - $before->getTimestamp());
        self::assertSame(0, $this->orders->markInProgressCalls);
        self::assertTrue($this->orders->isOpen());
        self::assertRetryQueued(retryCount: 2, delayMs: 20_000);

        // Printer is back.
        $this->startPrinter();
        $this->runWorkerOnce();

        self::assertSame(1, $this->orders->markInProgressCalls, 'marked in progress exactly once, after printing');
        self::assertFalse($this->orders->isOpen());
        self::assertFalse($this->printJobs->isPending(self::ORDER_NUMBER), 'the job is released after printing');
        self::assertSame([], $this->async->get(), 'nothing left in the queue');
        self::assertCount(0, $this->failed->getSent());

        $receipts = $this->receiptsReceivedByPrinter();
        self::assertCount(1, $receipts, 'the printer received exactly one receipt');
        self::assertStringContainsString('Bestellnummer: '.self::ORDER_NUMBER, $receipts[0]);
        self::assertCount(1, glob(self::RECEIPT_DIR.self::ORDER_NUMBER.'_*.txt'), 'exactly one receipt copy');

        $this->poll();
        self::assertCount(3, $this->async->getSent(), 'the order is no longer open, nothing new is queued');

        self::assertCount(2, $this->printWarnings(), 'one warning with the order number per failed attempt');
        self::assertCount(0, $this->logger->recordsOfLevel('error'));
    }

    public function testGivesUpAfterTheRetryWindowLogsAnErrorAndReleasesTheOrder(): void
    {
        $this->poll();
        $start = $this->clock->now();

        for ($attempt = 1; $attempt <= 11; ++$attempt) {
            $this->runWorkerOnce();
        }

        $elapsed = $this->clock->now()->getTimestamp() - $start->getTimestamp();
        self::assertGreaterThanOrEqual(1810, $elapsed, 'all ten backoff delays were waited');
        self::assertLessThan(1900, $elapsed);

        self::assertSame(0, $this->orders->markInProgressCalls);
        self::assertTrue($this->orders->isOpen(), 'the order stays open in Shopware');
        self::assertSame([], $this->async->get(), 'no further retry is queued');
        self::assertCount(1, $this->failed->getSent(), 'the job ended up in the failure transport');
        self::assertFalse($this->printJobs->isPending(self::ORDER_NUMBER), 'the job is released so the next poll can queue it again');

        $errors = $this->logger->recordsOfLevel('error');
        self::assertCount(1, $errors);
        self::assertSame(self::ORDER_NUMBER, $errors[0]['context']['orderNumber']);
        self::assertSame(11, $errors[0]['context']['attempts']);
        self::assertStringContainsString('Cannot connect to printer', $errors[0]['context']['error']);
        self::assertCount(10, $this->printWarnings());

        $this->poll();
        self::assertCount(12, $this->async->getSent(), 'a new retry window starts while the order is still open');
        self::assertTrue($this->printJobs->isPending(self::ORDER_NUMBER));
    }

    private function poll(): void
    {
        $this->bus->dispatch(new PrintOpenOrdersCommand(true));
    }

    /**
     * Processes the next message, waiting out any retry delay on the mock clock.
     * A Worker cannot be restarted after stop(), so each run gets a fresh one.
     */
    private function runWorkerOnce(): void
    {
        new Worker(['async' => $this->async], $this->bus, $this->dispatcher, null, null, $this->clock)->run();
    }

    /**
     * Warnings written by PrintOrderFailedListener; Messenger's retry listener logs its own,
     * without an order number.
     *
     * @return list<array{level: string, message: string, context: array}>
     */
    private function printWarnings(): array
    {
        return array_values(array_filter(
            $this->logger->recordsOfLevel('warning'),
            static fn (array $record) => isset($record['context']['orderNumber'])
        ));
    }

    private function assertRetryQueued(int $retryCount, int $delayMs): void
    {
        $sent = $this->async->getSent();
        $last = end($sent);
        self::assertInstanceOf(Envelope::class, $last);
        self::assertSame($retryCount, $last->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame($delayMs, $last->last(DelayStamp::class)?->getDelay());
        self::assertSame([], $this->async->get(), 'the retry is delayed, not available yet');
    }

    private static function reserveClosedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function startPrinter(): void
    {
        $this->printerServer = stream_socket_server(sprintf('tcp://127.0.0.1:%d', $this->printerPort), $errno, $errstr);
        self::assertNotFalse($this->printerServer, $errstr);
    }

    private function stopPrinter(): void
    {
        if (null !== $this->printerServer) {
            fclose($this->printerServer);
            $this->printerServer = null;
        }
    }

    /** @return list<string> one entry per connection the printer accepted */
    private function receiptsReceivedByPrinter(): array
    {
        self::assertNotNull($this->printerServer);
        $receipts = [];
        while ($client = @stream_socket_accept($this->printerServer, 0.2)) {
            $receipts[] = (string) stream_get_contents($client);
            fclose($client);
        }

        return $receipts;
    }

    private static function cleanReceiptDir(): void
    {
        if (!is_dir(self::RECEIPT_DIR)) {
            mkdir(self::RECEIPT_DIR, 0777, true);
        }
        foreach (glob(self::RECEIPT_DIR.'*') ?: [] as $file) {
            unlink($file);
        }
    }

    private static function createOpenOrder(): Order
    {
        return new Order(
            'order-id-10556',
            self::ORDER_NUMBER,
            null,
            '40,00',
            '0,00',
            new Address('Dwight Schrute', 'Schrute Farm 1', 'Scranton', '0123456789', null),
            [new OrderItem('26', 'Cordon Bleu', '40,00', 2, ReceiptPositionPrintTypeEnum::LABEL)],
            true,
            'Lieferung',
            new \DateTimeImmutable('2026-09-13 11:55:00'),
        );
    }
}
