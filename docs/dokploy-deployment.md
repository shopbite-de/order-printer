# Running one Order Printer per restaurant on Dokploy

Every restaurant gets its own Order Printer instance on the Dokploy host: its own Shopware
integration, its own printer, its own queue. There is no multi-tenant mode; an instance prints
**every** open order of the Shopware it is pointed at, so one Shopware (or one shop with its own
Admin API integration) maps to exactly one instance.

```
Dokploy project "order-printer"
  ├── order-printer-masala-mio   SHOPWARE_HOST=… PRINTER_DSN=tcp://100.x.y.z:9100   volume order-printer-masala-mio-data
  ├── order-printer-<shop-2>
  └── …
```

Image, environment variables and volumes are described in [docker.md](docker.md); the tailnet
that connects the host to the printers in [tailscale.md](tailscale.md); the Pi at the
restaurant in [pi-gateway.md](pi-gateway.md).

## Shopware integration per shop

The instance authenticates with an Admin API *Integration* (Settings › System › Integrations).
Create one per shop, with a role that has only what the printer uses:

| What                          | Why                                                |
| ----------------------------- | -------------------------------------------------- |
| Orders: view                  | `POST /api/search/order`, `/api/search/order-delivery` (line items, state, delivery address) |
| Orders: edit                  | `POST /api/_action/order/{id}/state/process` (mark *in progress*) |

Nothing else: no products, customers, media or settings. Create the role first (Settings ›
System › Users & permissions › Roles, e.g. `order-printer`), then the integration with that
role, and copy the access key id and secret straight into Dokploy; the secret is shown once.

## Environment per instance

Set in the service's *Environment* tab. The `compose.yaml` in this repository refuses to start
without the required ones.

| Variable                 | Value                                                                                 |
| ------------------------ | ------------------------------------------------------------------------------------- |
| `APP_ENV`                | `prod` (already the image default)                                                    |
| `APP_SECRET`             | `openssl rand -hex 16`, unique per instance                                           |
| `SHOPWARE_HOST`          | the shop's URL                                                                        |
| `SHOPWARE_CLIENT_ID`     | the integration's access key id                                                       |
| `SHOPWARE_CLIENT_SECRET` | its secret                                                                            |
| `PRINTER_DSN`            | `dummy://` until the printer is live, then `tcp://<tailnet-ip-of-the-pi>:9100`        |
| `SHOP_NAME`              | restaurant name, printed on `printer:test` receipts                                    |
| `RECEIPT_RETENTION_DAYS` | optional, default 30                                                                  |

### `dummy://` is a dry run

With `PRINTER_DSN=dummy://` the instance polls the shop, renders every open order, archives the
receipt copy under `/app/data/receipts/` and **leaves the order open**: nothing was printed, so
nothing is marked. Every poll queues the same open orders again, which is intended; the copies
overwrite each other (same file name per order). This is how a new instance is verified against
the live shop before it takes over printing, without stealing orders from the printer that is
still in service.

Consequence: switching a live instance *to* `dummy://` does not stop the shop, it just stops
marking. Switching it to `tcp://` is the cutover.

## Creating the service

1. Dokploy → project `order-printer` (create it once) → **Create service → Compose**.
2. Name `order-printer-<shop>`. Provider GitHub, repository `shopbite-de/order-printer`, branch
   `main`, compose path `compose.yaml`. Dokploy builds the image from the `Dockerfile`; no
   registry.
3. *Environment*: the variables above, `PRINTER_DSN=dummy://` for now.
4. *Advanced → Volumes*: the compose file already declares the named volume
   `order-printer-data`; Dokploy prefixes it per service, so instances do not share it. Nothing
   to add.
5. *Deployments → Auto Deploy* on, so every push to `main` rebuilds and restarts every
   instance (Dokploy installs the GitHub webhook itself when the repository is connected
   through the GitHub app).
6. **Deploy**. In *Logs* wait for both `Consuming messages from transport "scheduler_default"`
   and `Consuming messages from transport "async"`. The health check (`printer:check`) is green
   immediately with `dummy://`.
7. Terminal on the service: `su-exec app php bin/console printer:test` prints a test receipt,
   with `dummy://` it only confirms the configuration. `ls -la data/receipts/` shows a copy per
   open order once the poll ran.

No ports, no domain: the service only makes outbound connections (Shopware over HTTPS, the
printer over the tailnet).

## Cutover to the printer

Described in the cutover issue; the order matters to avoid double prints:

1. Stop whatever printed for this shop so far (the legacy supervisor on the Pi).
2. Set `PRINTER_DSN=tcp://<tailnet-ip-of-the-pi>:9100` on the instance and redeploy.
3. `printer:test` from the service terminal prints on the restaurant's printer.
4. Place a test order; the receipt prints and the order moves to *in progress*.

The health check now reflects the printer: *unhealthy* when the Pi is off, the printer is
unplugged or out of the tailnet.

## Updates

A push to `main` rebuilds and restarts every instance. The `/app/data` volume keeps the SQLite
queue, so print jobs that were retrying continue after the restart, and the deduplication locks
expire on their own. To confirm after a deploy: `supervisorctl status` in the service terminal
shows both programs `RUNNING`, `bin/console messenger:failed:show` is empty.

## Data on the host

`/app/data/receipts/` holds a copy of every printed receipt with name, address and phone
number. The scheduler deletes copies older than `RECEIPT_RETENTION_DAYS` (default 30) every
night at 04:00 Europe/Berlin. Set it to `0` only for debugging, and clean up by hand afterwards.

## Checklist: new restaurant

- [ ] Shopware: role `order-printer` (orders view + edit), integration for this shop, key id and secret in the password manager
- [ ] Pi gateway for the shop provisioned (`printer-<shop>` in the tailnet, see [pi-gateway.md](pi-gateway.md)) and its tailnet IP noted
- [ ] Dokploy: compose service `order-printer-<shop>` in project `order-printer`, branch `main`, auto deploy on
- [ ] Environment set: `APP_SECRET`, `SHOPWARE_*`, `SHOP_NAME`, `PRINTER_DSN=dummy://`
- [ ] Deployed, both consumers in the logs, health green
- [ ] `printer:test` from the service terminal runs without error
- [ ] Receipt copies of open orders appear in `data/receipts/`, orders stay open in Shopware
- [ ] Cutover: previous printing stopped, `PRINTER_DSN=tcp://<pi-ip>:9100`, redeploy, `printer:test` prints on site, test order prints and moves to *in progress*
- [ ] Health check goes red when the printer is unplugged (and green again when plugged in)
