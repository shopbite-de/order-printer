<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Symfony\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\Symfony\Log\JsonLogFormatter;

#[CoversClass(JsonLogFormatter::class)]
final class JsonLogFormatterTest extends TestCase
{
    public function testWritesLevelInterpolatedMessageAndContextAsOneJsonLine(): void
    {
        $line = new JsonLogFormatter()('error', 'Printing order {orderNumber} failed permanently after {attempts} attempt(s): {error}', [
            'orderNumber' => '12993',
            'attempts' => 11,
            'error' => 'Connection refused',
            'exception' => new \RuntimeException('Connection refused'),
        ]);

        self::assertStringNotContainsString("\n", $line);
        $record = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('error', $record['level']);
        self::assertSame('Printing order 12993 failed permanently after 11 attempt(s): Connection refused', $record['message']);
        self::assertSame(['orderNumber' => '12993', 'attempts' => 11, 'error' => 'Connection refused'], $record['context']);
        self::assertIsString($record['exception']);
        self::assertStringStartsWith('RuntimeException: Connection refused at ', $record['exception']);
        self::assertIsString($record['time']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $record['time']));
    }

    public function testNonScalarContextIsReducedToSomethingReadable(): void
    {
        $record = json_decode(new JsonLogFormatter()('notice', 'Message {message} handled', [
            'message' => new \stdClass(),
            'at' => new \DateTimeImmutable('2026-09-23 18:00:00+02:00'),
            'list' => [1, 2],
        ]), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($record);
        self::assertSame('Message [stdClass] handled', $record['message']);
        self::assertSame(['message' => '[stdClass]', 'at' => '2026-09-23T18:00:00+02:00', 'list' => 'array'], $record['context']);
        self::assertArrayNotHasKey('exception', $record);
    }
}
