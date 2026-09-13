<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Builds the printer connector from a DSN, see PrinterDsn for the supported schemes.
 *
 * @psalm-api
 */
final readonly class PrintConnectorFactory
{
    public const int DEFAULT_PORT = PrinterDsn::DEFAULT_PORT;
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
        $printer = PrinterDsn::parse($dsn);

        if (PrinterScheme::Dummy === $printer->scheme) {
            return new DummyPrintConnector();
        }

        if (PrinterScheme::File === $printer->scheme) {
            return new FilePrintConnector($printer->path ?? throw new \LogicException('file:// DSN without path'));
        }

        try {
            return new NetworkPrintConnector($printer->host ?? throw new \LogicException('tcp:// DSN without host'), $printer->port, $this->connectTimeout);
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Cannot connect to printer "%s": %s', $dsn, $e->getMessage()), 0, $e);
        }
    }
}
