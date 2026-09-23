<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Symfony\Log;

/**
 * Formats a log record as one JSON line for Symfony's default logger (config/services.yaml).
 *
 * The container's stdout/stderr is shipped to OpenObserve, which reads `level` and `message`
 * from JSON lines. Plain text lines would be classified by keyword, and a warning that quotes
 * "error 110" from the socket would end up as an error.
 *
 * @psalm-api
 */
final readonly class JsonLogFormatter
{
    public function __invoke(string $level, string $message, array $context): string
    {
        $record = [
            'time' => new \DateTimeImmutable()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'message' => self::interpolate($message, $context),
        ];

        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        if ([] !== $context) {
            $record['context'] = array_map(self::normalize(...), $context);
        }

        if ($exception instanceof \Throwable) {
            $record['exception'] = sprintf('%s: %s at %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine());
        }

        return json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"level":"error","message":"Log record could not be encoded"}';
    }

    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $normalized = self::normalize($value);
            if (\is_scalar($normalized)) {
                $replacements['{'.$key.'}'] = (string) $normalized;
            }
        }

        return strtr($message, $replacements);
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
            $value instanceof \Throwable => $value::class.': '.$value->getMessage(),
            $value instanceof \Stringable => (string) $value,
            \is_object($value) => '['.$value::class.']',
            default => get_debug_type($value),
        };
    }
}
