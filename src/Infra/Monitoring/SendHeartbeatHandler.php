<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Veliu\OrderPrinter\Infra\EscPos\PrinterChecker;

/**
 * Pushes `status=up` when the printer is reachable and `status=down` with the reason when it
 * is not, so Uptime Kuma alerts on a dead printer as well as on a dead instance (no push at
 * all). See docs/monitoring.md for the monitor settings.
 *
 * Never throws: a heartbeat that cannot be delivered must not disturb the poll loop it shares
 * the scheduler worker with.
 *
 * @psalm-api
 */
#[AsMessageHandler]
final readonly class SendHeartbeatHandler
{
    private const float PUSH_TIMEOUT = 5.0;

    public function __construct(
        #[Autowire(env: 'HEARTBEAT_URL')]
        private string $heartbeatUrl,
        #[Autowire(env: 'PRINTER_DSN')]
        private string $printerDsn,
        private PrinterChecker $checker,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /** @psalm-suppress UnusedParam the message only selects this handler */
    public function __invoke(SendHeartbeat $heartbeat): void
    {
        if ('' === $this->heartbeatUrl) {
            return;
        }

        $start = microtime(true);
        try {
            $this->checker->check($this->printerDsn);
            $query = ['status' => 'up', 'msg' => 'OK'];
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $query = ['status' => 'down', 'msg' => self::describe($e->getMessage())];
            $this->logger->warning('Heartbeat: printer not reachable: {reason}', ['reason' => $query['msg']]);
        }
        $query['ping'] = (string) (int) round((microtime(true) - $start) * 1000);

        try {
            // A push URL copied from Uptime Kuma ends in "?status=up&msg=OK&ping=", replace it.
            $url = strtok($this->heartbeatUrl, '?');
            $status = $this->httpClient->request('GET', $url, ['query' => $query, 'timeout' => self::PUSH_TIMEOUT])->getStatusCode();
            if ($status >= 300) {
                $this->logger->warning('Heartbeat push answered HTTP {status}.', ['status' => $status]);
            }
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Heartbeat push failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Tells the two usual outages apart. The Pi gateway closes port 9100 while the printer is
     * unplugged or off (printer-bridge.service is bound to the USB device), so the connect is
     * refused; when the Pi itself is offline the connect runs into the timeout instead.
     */
    public static function describe(string $error): string
    {
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, 'refused') => 'Drucker aus oder abgesteckt (Pi erreichbar, Port 9100 zu). '.$error,
            str_contains($lower, 'timed out'), str_contains($lower, 'no route') => 'Pi-Gateway nicht erreichbar (Pi aus, Netz oder Tailscale weg). '.$error,
            default => $error,
        };
    }
}
