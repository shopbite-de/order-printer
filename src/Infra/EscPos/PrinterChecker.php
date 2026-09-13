<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

/**
 * Checks that the printer behind a DSN is reachable without printing anything:
 * a TCP connect for tcp://, existence and writability for file://, always fine for dummy://.
 *
 * Used by printer:check (Docker health check / heartbeat), so it must answer quickly
 * even when the host is unreachable: the connect timeout is deliberately short.
 *
 * @psalm-api
 */
final readonly class PrinterChecker
{
    public const int DEFAULT_CONNECT_TIMEOUT = 3;

    /** @psalm-param positive-int $connectTimeout connect timeout in seconds for tcp:// */
    public function __construct(
        private int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the DSN is malformed or uses an unsupported scheme
     * @throws \RuntimeException         when the printer is not reachable, with a readable reason
     */
    public function check(string $dsn): void
    {
        $printer = PrinterDsn::parse($dsn);

        if (PrinterScheme::File === $printer->scheme) {
            $this->checkFile($printer->path ?? throw new \LogicException('file:// DSN without path'));
        } elseif (PrinterScheme::Tcp === $printer->scheme) {
            $this->checkTcp($printer);
        }
    }

    private function checkFile(string $path): void
    {
        // PHP stream wrappers (php://memory, php://stdout) have no file to inspect.
        if (str_starts_with($path, 'php://')) {
            return;
        }

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Printer device "%s" does not exist.', $path));
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(sprintf('Printer device "%s" is not writable by the current user.', $path));
        }
    }

    private function checkTcp(PrinterDsn $printer): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($printer->host ?? throw new \LogicException('tcp:// DSN without host'), $printer->port, $errno, $errstr, (float) $this->connectTimeout);

        if (false === $socket) {
            throw new \RuntimeException(sprintf('Cannot connect to printer "%s": %s', $printer->dsn, '' !== $errstr ? $errstr : sprintf('error %d', $errno)));
        }

        fclose($socket);
    }
}
