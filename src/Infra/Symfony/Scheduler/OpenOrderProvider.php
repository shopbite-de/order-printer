<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Symfony\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Veliu\OrderPrinter\Domain\Command\PrintOpenOrdersCommand;
use Veliu\OrderPrinter\Domain\Command\PurgeReceiptsCommand;
use Veliu\OrderPrinter\Infra\Monitoring\SendHeartbeat;

/** @psalm-suppress UnusedClass */
#[AsSchedule]
final readonly class OpenOrderProvider implements ScheduleProviderInterface
{
    public function __construct(
        /** Days to keep receipt copies; 0 keeps them forever. */
        #[Autowire(env: 'int:RECEIPT_RETENTION_DAYS')]
        private int $receiptRetentionDays = 30,
        /** Uptime Kuma push URL; empty disables the heartbeat. */
        #[Autowire(env: 'HEARTBEAT_URL')]
        private string $heartbeatUrl = '',
    ) {
    }

    #[\Override]
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule()->add(
            RecurringMessage::every('10 seconds', new PrintOpenOrdersCommand(true))
        );

        if ($this->receiptRetentionDays > 0) {
            $schedule->add(
                RecurringMessage::every(
                    '1 day',
                    new PurgeReceiptsCommand($this->receiptRetentionDays),
                    from: new \DateTimeImmutable('04:00', new \DateTimeZone('Europe/Berlin')),
                )
            );
        }

        if ('' !== $this->heartbeatUrl) {
            $schedule->add(RecurringMessage::every('1 minute', new SendHeartbeat()));
        }

        return $schedule;
    }
}
