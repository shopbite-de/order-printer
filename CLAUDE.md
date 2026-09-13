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
```

CI (`.github/workflows/ci.yml`) additionally runs `php -l` on every file, `php-cs-fixer --dry-run`, `composer audit`, and `doctrine:schema:validate`. PHPUnit is configured with `failOnDeprecation/Notice/Warning=true`, so deprecations from `src/` fail the build.

## Architecture

Layers under `src/`:

- `Domain/` — framework-free model and ports: `Order`, `OrderItem`, `Address`, `PrintOrderProcessorInterface`, `OrderRepositoryInterface`, the Messenger messages and handlers, and the receipt-position rules.
- `Infra/` — adapters: `Shopware/` (Admin API client), `EscPos/` (printer connector factory + receipt layout), `Symfony/Scheduler/`.
- `Adapter/Command/` — the single console command.

Service wiring is explicit in `config/services.yaml` and `config/services/*.yaml`; there is no directory-wide resource autoload. Every new class must be registered there, and every interface must be aliased to its implementation.

### Message flow

```
OpenOrderProvider (#[AsSchedule], every 10s)
  └─ PrintOpenOrdersCommand(markInProgress: true)          → transport "async"
       └─ PrintOpenOrdersHandler: OrderRepository::findNewNumbers()
            └─ PrintOrderCommand(orderNumber, markInProgress) → forced onto "sync" via TransportNamesStamp
                 └─ PrintOrderHandler: getByOrderNumber() → PrintOrderProcessorInterface
```

Both messages carry `#[AsMessage(transport: 'async')]`. The `async` transport is Doctrine/SQLite (`data/queue_<env>.db`) in prod, `sync://` in dev, and `in-memory://` in test, so in dev `app:print-order` prints immediately without a worker. `PrintOpenOrdersHandler` deliberately dispatches each `PrintOrderCommand` synchronously so one scheduler tick prints all open orders in order and failures surface in the same process. Failed messages go to the `failed` Doctrine queue.

Production runs two supervisor programs (`dev-ops/supervisor/conf.d/message.consumer.conf`): one consuming `scheduler_default`, one consuming `async`.

### Printing

`Infra\EscPos\PrintProcessor` is the only `PrintOrderProcessorInterface` implementation (bound in `config/services/escpos.yaml`). It uses `mike42/escpos-php` with a `MultiplePrintConnector` that writes to the real printer and simultaneously to `<project>/<DATA_DIR>/<orderNumber>_<createdAt>.txt`. All receipt layout lives here: 42-column width, German labels, header shows the shipping method, address is skipped when `shippingMethodName === 'Abholung'`, words starting with `+`/`-` are printed in reverse colours, long lines wrap with 4-space indent.

The printer connector comes from `Infra\EscPos\PrintConnectorFactory::create(PRINTER_DSN)`:

| DSN | Connector |
| --- | --- |
| `file://<path>` (e.g. `file:///dev/usb/lp0`, `file:///dev/null`, `file://php://memory`) | `FilePrintConnector` |
| `tcp://<host>[:<port>]` (port defaults to 9100) | `NetworkPrintConnector`, 5 s connect timeout, unreachable host throws `RuntimeException` |
| `dummy://` | `DummyPrintConnector`, output discarded |

Anything else throws `InvalidArgumentException`. There is deliberately no CUPS/`lp` path: the production container has no CUPS. `PrintProcessor` takes the factory as an optional last constructor argument, so tests build it with a `dummy://` or `file://php://memory` DSN and no mocks.

`PrintProcessor` calls `OrderRepository::markInProgress()` only when `markInProgress` is true **and** `Order::$isNew` (Shopware state `open`), so reprinting an in-progress order never touches state.

### Line-item print type

`OrderItem::$receiptPositionPrintType` (`ReceiptPositionPrintTypeEnum`) is read from the line-item payload key `shopbite_receipt_print_type`, defaulting to `LABEL`. `ReceiptPositionGenerator` turns `NUMBER` items into `<productNumber> <extras>` by regex-replacing the base product name up to the first ` +`, ` -`, or ` (`. Only top-level line items (`parentId === null`, i.e. the plugin's container items) are printed; children are dropped in `OrderRepository::transform()`.

### Shopware Admin API client

`Domain\Api\ClientInterface` + `RequestInterface` is a small hand-rolled PSR-18 wrapper (no Shopware SDK calls in practice despite `vin-sw/shopware-sdk` being required). Requests are value objects in `Infra/Shopware/Api/<Resource>/`, responses are parsed with `php-standard-library/php-standard-library` (the renamed `azjezz/psl`, `Psl\` namespace) type shapes in `Infra/Shopware/ResponseObject/` — extend the `arrayShape()` there when you need a new field from the API.

Auth is OAuth client credentials. `config/services/shopware.yaml` defines a second, unaliased `Client` instance (`access.token.provider.client`) with `$accessTokenProvider: null` that `AccessTokenProvider` uses to fetch tokens; the main `Client` gets the provider and adds the `Authorization` header. Do not autowire `Client` directly into new services, inject `ClientInterface`. Tokens are cached in memory and refreshed 10 seconds before expiry using `ClockInterface`.

`OrderStateEnum` maps transition names (`process`, `complete`, ...) used by `UpdateOrderStateRequest`; `fromTechnicalName()` maps the state names the API returns (`in_progress`, `completed`, ...).

## Environment

Copy `.env` to `.env.local`. Required: `SHOPWARE_HOST`, `SHOPWARE_CLIENT_ID`, `SHOPWARE_CLIENT_SECRET`, `PRINTER_DSN` (see the table above; `.env` defaults to `file:///dev/null`), `DATA_DIR` (relative to project dir, default `/data/receipts/`). `DATABASE_URL` points at SQLite and only backs the Messenger queue; there are no Doctrine entities, `doctrine.yaml` maps `src/Domain` but nothing is annotated.

## Conventions

- `declare(strict_types=1)`, `final readonly` classes, constructor promotion, `#[\Override]` on interface methods (Psalm enforces `MissingOverrideAttribute`; `make psalm-fix` adds them).
- Psalm `findUnusedCode=true`: classes only reached through DI or attributes need `/** @psalm-api */` or `@psalm-suppress UnusedClass`.
- Money is formatted as a string at the repository boundary (`number_format(..., 2, ',', '')`); domain objects carry the formatted string, not floats.
- Times printed on receipts are converted to `Europe/Berlin` in `PrintProcessor`.
