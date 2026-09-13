<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Support;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array}> */
    public function recordsOfLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $record) => $record['level'] === $level));
    }
}
