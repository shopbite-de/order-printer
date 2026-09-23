<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Monitoring;

/**
 * Check the printer and report the result to the heartbeat URL (an Uptime Kuma push monitor).
 *
 * Like PrintOpenOrdersCommand this is not routed to a transport: the scheduler worker handles
 * it inline, so a missing heartbeat also means the poll loop has stopped.
 */
final readonly class SendHeartbeat
{
}
