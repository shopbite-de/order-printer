# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Symfony 8 microservice (PHP 8.5) that polls a Shopware 6 shop for orders in state `open`, prints each one as an ESC/POS receipt on a thermal printer (local device file or raw TCP, selected by `PRINTER_DSN`), saves a copy of the raw ESC/POS bytes to `data/receipts/`, and moves the order to `in_progress`. It is part of the ShopBite monorepo (see `../CLAUDE.md`); the receipt print type per line item comes from the `shopbite_receipt_print_type` line-item payload written by `../shopware-plugin`.

Namespace root is `Veliu\OrderPrinter\` → `src/`, tests under `Veliu\OrderPrinter\Tests\` → `tests/`.

## Commands

```bash
composer install
make tests            # phpunit --testdox (unit + integration suites)
make cs-fix           # php-cs-fixer, @Symfony ruleset, risky rules allowed, src/ only
make psalm            # errorLevel 7, findUnusedCode=true, baseline in psalm-baseline.xml
make qa               # cs-fix + psalm + tests — run before committing
make psalm-baseline   # regenerate baseline after intentionally adding unused code
```

Run a single test:

```bash
XDEBUG_MODE=off vendor/bin/phpunit tests/Unit/Domain/Receipt/ReceiptPositionGeneratorTest.php
XDEBUG_MODE=off vendor/bin/phpunit --filter testSnapshots
XDEBUG_MODE=off vendor/bin/phpunit --testsuite unit      # or: integration
```

Receipt snapshots (`tests/Snapshots/<orderNumber>/{order.json,receipt.txt}`) are compared byte for byte, including ESC/POS control bytes. On mismatch the actual output lands in `tests/Snapshots/<orderNumber>/actual.txt`. To accept new output:

```bash
UPDATE_SNAPSHOTS=1 XDEBUG_MODE=off vendor/bin/phpunit --filter testSnapshots
```

To add a snapshot, drop a `FindDeliveriesResponse`-shaped JSON (an `/api/search/order-delivery` response) as `order.json` in a new directory and run with `UPDATE_SNAPSHOTS=1`.

Run the service locally:

```bash
bin/console app:print-order --order-number=10556 [--no-mark-in-progress]   # one order
bin/console app:print-order --all-open                                       # everything in state open
bin/console messenger:consume scheduler_default                              # poll loop (every 10s)
bin/console messenger:consume async                                          # worker (prod only, see below)
bin/console printer:check [--dsn=tcp://host:9100]                            # reachability only, exit 0/1, < 5 s (health check)
bin/console printer:test  [--dsn=tcp://host:9100]                            # prints a test receipt via the real connector
docker build -t order-printer:local .                                        # multi-stage image, see docs/docker.md
docker compose up -d                                                         # needs APP_SECRET, SHOPWARE_*, PRINTER_DSN in the env
```

CI (`.github/workflows/ci.yml`) additionally runs `php -l` on every file, `php-cs-fixer --dry-run`, `composer audit`, and `doctrine:schema:validate`. PHPUnit is configured with `failOnDeprecation/Notice/Warning=true`, so deprecations from `src/` fail the build.

## Architecture

Layers under `src/`:

- `Domain/` — model and ports: `Order`, `OrderItem`, `Address`, `PrintOrderProcessorInterface`, `OrderRepositoryInterface`, the Messenger messages and handlers, and the receipt-position rules.
- `Infra/` — adapters: `Shopware/` (Admin API client), `EscPos/` (printer connector factory + receipt layout), `Symfony/Scheduler/`, `Symfony/Messenger/` (failed-job logging).
- `Adapter/Command/` — the single console command.

Service wiring is explicit in `config/services.yaml` and `config/services/*.yaml`; there is no directory-wide resource autoload. Every new class must be registered there, and every interface must be aliased to its implementation.

### Message flow

```
OpenOrderProvider (#[AsSchedule], handled inside the scheduler_default worker)
  ├─ PurgeReceiptsCommand(retentionDays), daily 04:00 Europe/Berlin → no transport: PurgeReceiptsHandler deletes
  │    receipt copies older than RECEIPT_RETENTION_DAYS (0 = never scheduled) via ReceiptArchiveInterface
  ├─ Infra\Monitoring\SendHeartbeat, every minute, only when HEARTBEAT_URL is set → no transport: SendHeartbeatHandler
  │    runs PrinterChecker and pushes status=up|down (+ reason) to the Uptime Kuma push URL, never throws (docs/monitoring.md)
  └─ PrintOpenOrdersCommand(markInProgress: true), every 10 s → no transport: handled synchronously
       └─ PrintOpenOrdersHandler: OrderRepository::findNewNumbers()
            └─ PrintOrderCommand(orderNumber, markInProgress) + DeduplicateStamp("print-order-<n>") → transport "async"
                 ├─ DeduplicateMiddleware: lock already held (queued/retrying) → message silently dropped
                 └─ PrintOrderHandler: getByOrderNumber() → PrintOrderProcessorInterface → lock released
```

`PrintOpenOrdersCommand` is deliberately **not** routed to a transport: the scheduler worker runs the poll inline, a failed poll (Shopware down) is just logged and repeated 10 s later, never retried or sent to the failure transport. `PrintOrderCommand` carries `#[AsMessage(transport: 'async')]`. The `async` transport is Doctrine/SQLite (`data/queue_<env>.db`) in prod, `sync://` in dev, and `in-memory://` in test, so in dev `app:print-order` prints immediately without a worker.

Production runs two supervisor programs, one consuming `scheduler_default` (polling), one consuming `async` (printing): in Docker via `docker/supervisord.conf` (logs to stdout, programs run as uid 1000 `app`, `docker/entrypoint.sh` runs `messenger:setup-transports` first), on a bare host via `dev-ops/supervisor/conf.d/message.consumer.conf`. The image (`Dockerfile`, `compose.yaml`, `docs/docker.md`) is built in CI as a smoke test; its `HEALTHCHECK` is `printer:check`. `composer install` runs on the runtime PHP inside the build, so a missing extension fails the build (that is how `bcmath` was found). Note: `doctrine:database:create --if-not-exists` does not work on SQLite, and DoctrineBundle 3 rejects `proxy_dir`, which had broken `APP_ENV=prod` until the Docker work.

### Retries and de-duplication

- `async` has `retry_strategy` 10 retries, 10 s delay, multiplier 2, max 5 min, jitter 0: 10, 20, 40, 80, 160 s, then 5 × 300 s = 1810 s ≈ 30 min before a job fails permanently. `PrintRetryFlowTest` pins these numbers.
- `PrintProcessor` marks the order in progress only after `Printer::close()` succeeded, so a failed print leaves the order `open`; the deduplication lock is released by Messenger only after the handler returned.
- De-duplication is Messenger's own `DeduplicateStamp` (`symfony/lock`): `PrintOpenOrdersHandler` stamps every queued `PrintOrderCommand` with key `print-order-<number>` and `DEDUPLICATION_TTL` 7200 s. `DeduplicateMiddleware` acquires the lock on dispatch and drops the message while it is held; the lock is released after a successful handle and, via `ReleaseDeduplicationLockOnFailureListener`, when Messenger gives up. The TTL only covers a worker dying mid-job and must exceed retry window + Doctrine `redeliver_timeout` (1 h). The store is `doctrine.dbal.default_connection` (`config/packages/lock.yaml`), table `lock_keys` auto-created: it must be a shared, token-based store because the scheduler worker acquires and the print worker releases; `flock` would neither cross processes nor expire. With the dev `sync://` transport the handler releases the lock itself when the inline print throws.
- `PrintOrderFailedListener` (`WorkerMessageFailedEvent`, after Messenger's retry listener) logs a `warning` per retried attempt and an `error` with `orderNumber`, `attempts` and `error` context when Messenger gives up. The message also lands in the `failed` transport for inspection; the released lock lets the next poll re-queue the order if it is still open.
- `OrderNotFound` is wrapped in `UnrecoverableMessageHandlingException`: no retries for orders Shopware does not know.
- `PrintOrderHandler` logs `notice` "Order <n> printed." after every successful print; monitoring counts these lines.
- Logging: no Monolog. `config/services.yaml` redefines Symfony's built-in `logger` (`HttpKernel\Log\Logger`) to write JSON lines (`Infra\Symfony\Log\JsonLogFormatter`) to stderr from `LOG_LEVEL` up; without that it would log plain text from `error` up only. PHPUnit sets `LOG_LEVEL=emergency`, unit tests use `Tests\Support\SpyLogger`.
- Known gap: if the print succeeds but `markInProgress()` fails (Shopware API error), the retry prints the receipt again.

### Printing

`Infra\EscPos\PrintProcessor` is the only `PrintOrderProcessorInterface` implementation (bound in `config/services/escpos.yaml`). It uses `mike42/escpos-php` with a `MultiplePrintConnector` that writes to the real printer and simultaneously to `<project>/<DATA_DIR>/<orderNumber>_<createdAt>.txt`. All receipt layout lives here: 42-column width, German labels, header shows the shipping method, address is skipped when `shippingMethodName === 'Abholung'`, words starting with `+`/`-` are printed in reverse colours, long lines wrap with 4-space indent.

The printer connector comes from `Infra\EscPos\PrintConnectorFactory::create(PRINTER_DSN)`:

| DSN | Connector |
| --- | --- |
| `file://<path>` (e.g. `file:///dev/usb/lp0`, `file:///dev/null`, `file://php://memory`) | `FilePrintConnector` |
| `tcp://<host>[:<port>]` (port defaults to 9100) | `NetworkPrintConnector`, 5 s connect timeout, unreachable host throws `RuntimeException` |
| `dummy://` | `DummyPrintConnector`, output discarded |

Anything else throws `InvalidArgumentException`. DSN parsing lives in `PrinterDsn::parse()` (`PrinterScheme` enum), shared with `PrinterChecker` (reachability without printing: TCP connect with 3 s timeout, `file_exists` + `is_writable` for files, `php://` wrappers skipped) and `TestReceiptPrinter` (the `printer:test` receipt: `SHOP_NAME` or the `SHOPWARE_HOST` domain, time in Europe/Berlin, `gethostname()`, DSN). The commands in `Adapter/Command/` map exceptions to exit code 1 with the message. There is deliberately no CUPS/`lp` path: the production container has no CUPS. `PrintProcessor` takes the factory as an optional last constructor argument, so tests build it with a `dummy://` or `file://php://memory` DSN and no mocks.

`PrintProcessor` calls `OrderRepository::markInProgress()` only when `markInProgress` is true **and** `Order::$isNew` (Shopware state `open`) **and** the DSN is not `dummy://`, so reprinting an in-progress order never touches state, and a `dummy://` instance is a dry run: receipts are archived, orders stay `open` and are re-queued by every poll (safe against a live shop, see `docs/dokploy-deployment.md`).

### Line-item print type

`OrderItem::$receiptPositionPrintType` (`ReceiptPositionPrintTypeEnum`) is read from the line-item payload key `shopbite_receipt_print_type`, defaulting to `LABEL`. `ReceiptPositionGenerator` turns `NUMBER` items into `<productNumber> <extras>` by regex-replacing the base product name up to the first ` +`, ` -`, or ` (`. Only top-level line items (`parentId === null`, i.e. the plugin's container items) are printed; children are dropped in `OrderRepository::transform()`.

### Shopware Admin API client

`Domain\Api\ClientInterface` + `RequestInterface` is a small hand-rolled PSR-18 wrapper (no Shopware SDK calls in practice despite `vin-sw/shopware-sdk` being required). Requests are value objects in `Infra/Shopware/Api/<Resource>/`, responses are parsed with `php-standard-library/php-standard-library` (the renamed `azjezz/psl`, `Psl\` namespace) type shapes in `Infra/Shopware/ResponseObject/` — extend the `arrayShape()` there when you need a new field from the API.

Auth is OAuth client credentials. `config/services/shopware.yaml` defines a second, unaliased `Client` instance (`access.token.provider.client`) with `$accessTokenProvider: null` that `AccessTokenProvider` uses to fetch tokens; the main `Client` gets the provider and adds the `Authorization` header. Do not autowire `Client` directly into new services, inject `ClientInterface`. Tokens are cached in memory and refreshed 10 seconds before expiry using `ClockInterface`.

`OrderStateEnum` maps transition names (`process`, `complete`, ...) used by `UpdateOrderStateRequest`; `fromTechnicalName()` maps the state names the API returns (`in_progress`, `completed`, ...).

## Environment

Copy `.env` to `.env.local`. Required: `SHOPWARE_HOST`, `SHOPWARE_CLIENT_ID`, `SHOPWARE_CLIENT_SECRET`, `PRINTER_DSN` (see the table above; `.env` defaults to `file:///dev/null`), `DATA_DIR` (relative to project dir, default `/data/receipts/`), `RECEIPT_RETENTION_DAYS` (default 30). Optional: `SHOP_NAME` for test receipts (read with `env(default::SHOP_NAME)`, so it may be absent), `HEARTBEAT_URL` (empty = no heartbeat), `LOG_LEVEL` (default `notice`). `DATABASE_URL` points at SQLite and backs the Messenger queue (`messenger_messages`) and the deduplication locks (`lock_keys`); both tables are created on first use. There are no Doctrine entities.

## Conventions

- `declare(strict_types=1)`, `final readonly` classes, constructor promotion, `#[\Override]` on interface methods (Psalm enforces `MissingOverrideAttribute`; `make psalm-fix` adds them).
- Psalm `findUnusedCode=true`: classes only reached through DI or attributes need `/** @psalm-api */` or `@psalm-suppress UnusedClass`.
- Money is formatted as a string at the repository boundary (`number_format(..., 2, ',', '')`); domain objects carry the formatted string, not floats.
- Times printed on receipts are converted to `Europe/Berlin` in `PrintProcessor`.
