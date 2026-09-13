# Order Printer for Shopware 6

A small Symfony 8 service that watches a Shopware 6 shop for new orders and prints each one as a receipt on an ESC/POS thermal printer. It runs next to the shop (on a server, in a container on Dokploy) or on a Raspberry Pi at the restaurant, and reaches the printer either as a local USB/serial device or over the network on TCP port 9100. No CUPS, no drivers.

## How it works

```
Shopware 6 ──(Admin API, every 10 s)──► scheduler worker ──► SQLite queue ──► print worker ──► printer
                                                                                      └──► copy in data/receipts/
```

1. The **scheduler worker** polls the Admin API every 10 seconds for orders in state *open* and queues one print job per order.
2. The **print worker** renders the receipt (42 columns, order number, time, delivery or pickup, address, items with extras, totals) and sends it to the printer. A copy of the raw bytes is kept in `data/receipts/`.
3. Only after the receipt was printed is the order set to *in progress* in Shopware. A failed print leaves it *open* and is retried with backoff for about 30 minutes; the poll never queues the same order twice while a job is pending (see [Failure handling](#failure-handling)).

Line items are printed by name, or by product number when the ShopBite Shopware plugin marks them with the `number` receipt print type (for example `26 +Knoblauch` instead of `Pizza Mix +Knoblauch`).

## Quick start

### With Docker (recommended for servers and Dokploy)

```bash
APP_SECRET=$(openssl rand -hex 16) \
SHOPWARE_HOST=https://shopware.example.com \
SHOPWARE_CLIENT_ID=... SHOPWARE_CLIENT_SECRET=... \
PRINTER_DSN=tcp://192.168.1.50:9100 \
docker compose up -d

docker compose logs -f
docker compose exec order-printer su-exec app php bin/console printer:test
```

The image runs both workers under supervisor, checks the printer connection as its health check and keeps the queue and receipt copies on the `/app/data` volume. Build, environment variables, USB devices and the Dokploy setup are described in [docs/docker.md](docs/docker.md).

### On a host (Raspberry Pi, bare server)

Requirements: PHP 8.5 with `intl`, `mbstring`, `bcmath`, `pdo_sqlite` and `sqlite3`; Composer.

```bash
git clone https://github.com/shopbite-de/order-printer.git
cd order-printer
composer install
cp .env .env.local            # then fill in the variables below
bin/console doctrine:database:create
bin/console printer:test       # prints a test receipt
```

Run the two workers, for example under supervisor (`dev-ops/supervisor/conf.d/message.consumer.conf`):

```bash
bin/console messenger:consume scheduler_default --time-limit=3600   # polls Shopware
bin/console messenger:consume async --time-limit=3600               # prints
```

In the `dev` environment print jobs run synchronously, so `bin/console app:print-order --all-open` prints without any worker.

## Configuration

All configuration is done through environment variables (`.env.local` on a host, the container environment in Docker).

| Variable                 | Required | Description                                                                                                                  |
| ------------------------ | -------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `SHOPWARE_HOST`          | yes      | Shop URL, e.g. `https://shopware.example.com`                                                                                |
| `SHOPWARE_CLIENT_ID`     | yes      | Client id of a Shopware *Integration* (Settings › System › Integrations) with read and update access to orders               |
| `SHOPWARE_CLIENT_SECRET` | yes      | Its client secret                                                                                                            |
| `PRINTER_DSN`            | yes      | How to reach the printer, see below                                                                                          |
| `APP_SECRET`             | prod     | Any random string                                                                                                            |
| `DATA_DIR`               | no       | Where receipt copies are stored, relative to the project root. Default `/data/receipts/`                                     |
| `SHOP_NAME`              | no       | Restaurant name on `printer:test` receipts. Defaults to the domain of `SHOPWARE_HOST`                                        |

### Printer DSN

| `PRINTER_DSN`              | Use for                                                                                                      |
| -------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `file:///dev/usb/lp0`      | USB or serial printer on this machine. The user running the service needs write access (`usermod -aG lp …`). Any writable path works, e.g. `file:///dev/null` in development |
| `tcp://192.168.1.50:9100`  | Network printer, or a Raspberry Pi acting as printer gateway, speaking raw ESC/POS. The port defaults to 9100; connecting times out after 5 seconds |
| `dummy://`                 | No printer. Receipts are only archived in `DATA_DIR`                                                         |

## Commands

| Command                                              | Purpose                                                                                                       |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `bin/console printer:check [--dsn=…]`                | Is the printer reachable? Prints nothing, exit code 0/1, answers in under 5 s. Used as Docker health check    |
| `bin/console printer:test [--dsn=…]`                 | Prints a test receipt with shop name, time, hostname and DSN through the same connector as real receipts      |
| `bin/console app:print-order --order-number=10556`   | Prints one order. Add `--no-mark-in-progress` to leave the Shopware state untouched                          |
| `bin/console app:print-order --all-open`             | Queues every order in state *open*                                                                            |
| `bin/console messenger:consume scheduler_default`    | The poll worker                                                                                               |
| `bin/console messenger:consume async`                | The print worker                                                                                              |
| `bin/console messenger:failed:show` / `:remove <id>` | Inspect and clean up permanently failed print jobs                                                            |

Both `printer:*` commands accept `--dsn=tcp://…` to try another printer than the configured one and exit with 1 and a readable message on failure.

## Failure handling

Short printer outages (Pi reboot, restaurant WLAN, paper out) are expected, so a print job is never dropped after one failed attempt.

- **Retries with backoff.** A failed print job is retried 10 times: after 10 s, 20 s, 40 s, 80 s, 160 s and then every 5 minutes, about 30 minutes in total (`retry_strategy` in `config/packages/messenger.yaml`).
- **The order stays *open* in Shopware** until the receipt was actually printed. Only a successful print marks it as *in progress*.
- **No duplicates.** Every queued print job holds a Messenger deduplication lock for its order (stored in the same SQLite database), so the 10-second poll does not queue an order that is already queued or retrying. When the printer comes back, each order is printed exactly once.
- **Permanent failure.** After the last retry the job is moved to the `failed` transport, its lock is released and an `error` log line with the order number is written, e.g. `Printing order 10556 failed permanently after 11 attempt(s): Cannot connect to printer "tcp://…"`. As long as the order is still *open* in Shopware the next poll queues it again and a new retry window starts, so nothing is lost as long as the printer comes back eventually.
- **Orders Shopware does not know** fail immediately without retries.

Failed messages are kept for inspection only; since the poll re-queues open orders on its own, prefer `messenger:failed:remove` over `messenger:failed:retry` to avoid printing a receipt twice.

## Development

```bash
make tests            # PHPUnit, unit + integration suites
make cs-fix           # php-cs-fixer
make psalm            # static analysis
make qa               # all of the above
```

- Receipt layout is covered by byte-for-byte snapshots in `tests/Snapshots/<orderNumber>/`. On a mismatch the actual output lands next to the snapshot as `actual.txt`; accept it with `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter testSnapshots`.
- `tests/Integration/PrintRetryFlowTest` drives a real Messenger worker against a closed TCP port that is opened later, on a mock clock, to prove the retry and deduplication behaviour end to end.
- CI (`.github/workflows/ci.yml`) runs syntax check, Psalm, PHPUnit, php-cs-fixer, `composer audit`, the Doctrine schema validation and a `docker build` smoke test.

Architecture notes for contributors are in [CLAUDE.md](CLAUDE.md); user documentation lives on [shopbite.de](https://shopbite.de) (receipt printer section).
