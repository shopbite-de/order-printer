# Running the Order Printer in Docker

The image runs both Messenger consumers (the 10-second Shopware poll and the print worker)
under supervisor in one container. It is meant for a central deployment, e.g. on Dokploy,
printing to a Raspberry Pi or a network printer over TCP.

## Build

```bash
docker build -t shopbite/order-printer .
```

Multi-stage `Dockerfile`:

| Stage     | Base                 | Purpose                                                                                   |
| --------- | -------------------- | ----------------------------------------------------------------------------------------- |
| `base`    | `php:8.5-cli-alpine` | adds `bcmath`, `intl`, `pcntl`; production `php.ini`; `APP_ENV=prod`                       |
| `vendor`  | `base`               | `composer install --no-dev` on the real runtime PHP, so platform requirements are verified |
| `runtime` | `base`               | app code, authoritative classmap, warmed cache, supervisor, `su-exec`, health check        |

The image is about 73 MB compressed (what a registry stores and transfers) and 334 MB
uncompressed on disk. The `php:8.5-cli-alpine` base alone is 195 MB uncompressed, and supervisor
adds about 50 MB for its Python runtime, so an uncompressed image below 200 MB is not possible
with this base.

## Run

```bash
docker run -d --name order-printer \
  -e APP_SECRET=$(openssl rand -hex 16) \
  -e SHOPWARE_HOST=https://shopware.shopbite.de \
  -e SHOPWARE_CLIENT_ID=... \
  -e SHOPWARE_CLIENT_SECRET=... \
  -e PRINTER_DSN=tcp://100.64.0.5:9100 \
  -v order-printer-data:/app/data \
  shopbite/order-printer
```

or with the reference `compose.yaml`:

```bash
APP_SECRET=... SHOPWARE_HOST=... SHOPWARE_CLIENT_ID=... SHOPWARE_CLIENT_SECRET=... PRINTER_DSN=dummy:// \
  docker compose up -d
docker compose logs -f
docker compose exec order-printer supervisorctl status
docker compose exec order-printer su-exec app php bin/console printer:test
```

### Environment variables

| Variable                 | Required | Description                                                                                                    |
| ------------------------ | -------- | -------------------------------------------------------------------------------------------------------------- |
| `SHOPWARE_HOST`          | yes      | Shop URL, e.g. `https://shopware.shopbite.de`                                                                  |
| `SHOPWARE_CLIENT_ID`     | yes      | Admin API integration client id                                                                                |
| `SHOPWARE_CLIENT_SECRET` | yes      | Admin API integration client secret                                                                            |
| `PRINTER_DSN`            | yes      | `tcp://<host>:9100` (network printer / Pi gateway), `file:///dev/usb/lp0` (USB device), `dummy://` (dry run: no printer, orders stay open) |
| `APP_SECRET`             | yes      | Any random string (Symfony kernel secret)                                                                      |
| `SHOP_NAME`              | no       | Restaurant name on `printer:test` receipts, defaults to the `SHOPWARE_HOST` domain                            |
| `DATA_DIR`               | no       | Receipt copies, relative to `/app`; default `/data/receipts/`                                                  |
| `RECEIPT_RETENTION_DAYS` | no       | Delete receipt copies (personal data) after this many days, daily at 04:00 Europe/Berlin; default `30`, `0` keeps them |

The image ships the repository's `.env` as defaults; `.env.local` is never copied. Real
environment variables always win.

### Volumes

| Path        | Content                                                                                        |
| ----------- | ---------------------------------------------------------------------------------------------- |
| `/app/data` | `queue_prod.db` (SQLite: Messenger queue, failed messages, deduplication locks) and `receipts/` |

Without the volume, queued and failed print jobs are lost when the container is recreated.
The entrypoint `chown`s the volume to the unprivileged `app` user (uid 1000) on every start.

### USB printer on the Docker host

Map the device and give the container the host's `lp` group so `app` may write to it:

```yaml
    environment:
      PRINTER_DSN: file:///dev/usb/lp0
    devices:
      - /dev/usb/lp0:/dev/usb/lp0
    group_add:
      - "7"    # getent group lp | cut -d: -f3
```

## What happens at start

`docker/entrypoint.sh` runs as root, prepares `/app/data` and `/app/var`, runs
`messenger:setup-transports` as `app` (creates the SQLite file and the `messenger_messages`
table, fails loudly if the volume is not writable), then `exec`s supervisor.
`docker/supervisord.conf` starts:

- `scheduler`: `messenger:consume scheduler_default --time-limit=3600` (polls Shopware, queues print jobs)
- `worker`: `messenger:consume async --time-limit=3600` (prints, retries with backoff)

Both run as `app`, log to the container's stdout/stderr, and are restarted by supervisor when
the hourly time limit ends. `pcntl` is installed so `SIGTERM` stops a worker after the current
message. There are no Doctrine migrations in this project; the `lock_keys` table is created on
first use.

## Health check

```dockerfile
HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD su-exec app php bin/console printer:check || exit 1
```

`printer:check` verifies the printer is reachable (TCP connect with a 3 s timeout, or a writable
device file) without printing. The container is `healthy` when the printer answers and
`unhealthy` after three failures, which Dokploy shows and can alert on.

## Dokploy

One compose service per restaurant; the full procedure and the checklist for a new restaurant
are in [dokploy-deployment.md](dokploy-deployment.md). In short:

1. Create a **Compose** service from this repository (branch `main`, compose file `compose.yaml`).
   Dokploy builds the image itself; no registry is needed.
2. Set the environment variables from the table above in the service's *Environment* tab.
   For a Pi on Tailscale use its Tailscale IP: `PRINTER_DSN=tcp://100.x.y.z:9100`
   (tailnet, tags and ACL: [tailscale.md](tailscale.md)).
3. Deploy. Check *Logs* for `Consuming messages from transport "async"` and the health status.
4. Send a test receipt: open a terminal on the service and run
   `su-exec app php bin/console printer:test`.

Pushing to GHCR from GitHub Actions is only worth it once several instances should pull the
same image; CI currently builds the image as a smoke test only.

## Updating

Redeploy in Dokploy (or `docker compose up -d --build`). The volume keeps the queue, so print
jobs that were retrying continue after the restart; the deduplication locks expire after two
hours at the latest.
