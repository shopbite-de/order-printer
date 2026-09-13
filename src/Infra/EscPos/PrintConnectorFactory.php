<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Builds the printer connector from a DSN.
 *
 * Supported schemes:
 *  - file://<path>            local device or file, e.g. file:///dev/usb/lp0
 *  - tcp://<host>[:<port>]    raw ESC/POS over TCP, port defaults to 9100
 *  - dummy://                 discards output (deployments without a printer)
 *
 * @psalm-api
 */
final readonly class PrintConnectorFactory
{
    public const int DEFAULT_PORT = 9100;
    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    /** @psalm-param positive-int $connectTimeout connect timeout in seconds for tcp:// */
    public function __construct(
        private int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the DSN is malformed or uses an unsupported scheme
     * @throws \RuntimeException         when a tcp:// host cannot be reached within the timeout
     */
    public function create(string $dsn): PrintConnector
    {
        if ('dummy://' === $dsn) {
            return new DummyPrintConnector();
        }

        if (str_starts_with($dsn, 'file://')) {
            $path = substr($dsn, \strlen('file://'));
            if ('' === $path) {
                throw new \InvalidArgumentException(sprintf('Printer DSN "%s" is missing a file path.', $dsn));
            }

            return new FilePrintConnector($path);
        }

        if (str_starts_with($dsn, 'tcp://')) {
            $parts = parse_url($dsn);
            $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
            if ('' === $host) {
                throw new \InvalidArgumentException(sprintf('Printer DSN "%s" is missing a host.', $dsn));
            }
            $port = \is_array($parts) ? ($parts['port'] ?? self::DEFAULT_PORT) : self::DEFAULT_PORT;

            try {
                return new NetworkPrintConnector($host, $port, $this->connectTimeout);
            } catch (\Exception $e) {
                throw new \RuntimeException(sprintf('Cannot connect to printer "%s": %s', $dsn, $e->getMessage()), 0, $e);
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported printer DSN "%s". Expected file://<path>, tcp://<host>[:<port>] or dummy://.', $dsn));
    }
}
