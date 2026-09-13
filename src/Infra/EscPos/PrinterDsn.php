<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

/**
 * Parsed PRINTER_DSN.
 *
 *  - file://<path>            local device or file, e.g. file:///dev/usb/lp0
 *  - tcp://<host>[:<port>]    raw ESC/POS over TCP, port defaults to 9100
 *  - dummy://                 discards output (deployments without a printer)
 *
 * @psalm-api
 */
final readonly class PrinterDsn
{
    public const int DEFAULT_PORT = 9100;

    /**
     * @psalm-param non-empty-string      $dsn
     * @psalm-param non-empty-string|null $path
     * @psalm-param non-empty-string|null $host
     */
    private function __construct(
        public string $dsn,
        public PrinterScheme $scheme,
        public ?string $path = null,
        public ?string $host = null,
        public int $port = self::DEFAULT_PORT,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the DSN is malformed or uses an unsupported scheme
     */
    public static function parse(string $dsn): self
    {
        if ('dummy://' === $dsn) {
            return new self($dsn, PrinterScheme::Dummy);
        }

        if (str_starts_with($dsn, 'file://')) {
            $path = substr($dsn, \strlen('file://'));
            if ('' === $path) {
                throw new \InvalidArgumentException(sprintf('Printer DSN "%s" is missing a file path.', $dsn));
            }

            return new self($dsn, PrinterScheme::File, path: $path);
        }

        if (str_starts_with($dsn, 'tcp://')) {
            $parts = parse_url($dsn);
            $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
            if ('' === $host) {
                throw new \InvalidArgumentException(sprintf('Printer DSN "%s" is missing a host.', $dsn));
            }
            $port = \is_array($parts) ? ($parts['port'] ?? self::DEFAULT_PORT) : self::DEFAULT_PORT;

            return new self($dsn, PrinterScheme::Tcp, host: $host, port: $port);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported printer DSN "%s". Expected file://<path>, tcp://<host>[:<port>] or dummy://.', $dsn));
    }
}
