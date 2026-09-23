# Monitoring

A printer that stops printing must show up in monitoring before the restaurant calls. Every
instance reports in two ways:

| Signal | Where | Catches |
| --- | --- | --- |
| Heartbeat every minute | Uptime Kuma push monitor per restaurant (https://status.shopbite.de) | printer unplugged or off, Pi offline, instance or scheduler dead |
| Container logs (JSON lines) | OpenObserve stream `docker_logs` (https://observe.shopbite.de), shipped by the Vector agent on the Dokploy host | print jobs that failed permanently, Shopware unreachable, an evening without a single receipt |

Both alert by mail to lirim@veliu.net.

## Heartbeat

With `HEARTBEAT_URL` set, the scheduler worker runs `SendHeartbeat` once a minute, inline like the
10-second poll. It runs the same check as `printer:check` and pushes the result:

| Check result | Push |
| --- | --- |
| printer reachable | `?status=up&msg=OK&ping=<check ms>` |
| connect refused | `?status=down&msg=Drucker aus oder abgesteckt (Pi erreichbar, Port 9100 zu). …` |
| connect timed out | `?status=down&msg=Pi-Gateway nicht erreichbar (Pi aus, Netz oder Tailscale weg). …` |

The two cases can be told apart because `printer-bridge.service` on the Pi is bound to the USB
device ([pi-gateway.md](pi-gateway.md#why-the-bridge-is-bound-to-the-device)): while the printer is
unplugged or switched off, port 9100 is closed and the connect is refused at once; when the Pi
itself is gone, the connect waits for the 3-second timeout.

Because the heartbeat shares the scheduler worker with the poll, a missing heartbeat also means
that no orders are being fetched. It does not prove that the `async` worker (the one that prints)
is alive; supervisor restarts it when it exits, and a stuck job shows up as a failed job after
about 30 minutes.

A heartbeat that cannot be delivered (Kuma down) is logged as a warning and never disturbs the
poll. Without `HEARTBEAT_URL` nothing is scheduled.

### Uptime Kuma monitor per restaurant

Add a monitor of type **Push**:

| Setting | Value | Why |
| --- | --- | --- |
| Name | `Bondrucker <Restaurant>` | |
| Heartbeat interval | 120 s | one missed push is not an outage |
| Retries | 2 | |
| Heartbeat retry interval | 60 s | |
| Notification | Mail an Lirim | |

Kuma applies the retries to `status=down` pushes as well, so the monitor goes down after the third
failed check in a row, about 3 minutes after the printer was unplugged. Without any push (instance
stopped, host down) it goes down after roughly 4 minutes (120 s + 2 × 60 s). Both are within the
5 minutes the restaurant can wait. The first `up` push resolves the alert.

Copy the push URL from Kuma into the service's environment in Dokploy as `HEARTBEAT_URL`; the
`?status=up&msg=OK&ping=` Kuma appends is replaced by the instance, so it may stay. Then redeploy
(outside opening hours: a redeploy interrupts printing for a few seconds).

## Logs

The container writes JSON lines to stderr (`config/services.yaml`, `JsonLogFormatter`), from
`LOG_LEVEL` up (default `notice`):

```json
{"time":"2026-09-23T18:04:11.201+00:00","level":"notice","message":"Order 12993 printed.","context":{"orderNumber":"12993"}}
```

Vector takes `level` and `message` from these lines into the fields `level` and `msg` of
`docker_logs`; `container` is the Dokploy container name, e.g.
`lafattoria-order-printer-5wbitw-order-printer-1`. Supervisor's own lines and the
`messenger:consume` banner are plain text and land as `info`.

| Level | Message | Meaning |
| --- | --- | --- |
| `notice` | `Order <n> printed.` | receipt printed, one per order |
| `warning` | `Printing order <n> failed (attempt <k>), will retry: …` | printer unreachable, Messenger retries (10 s up to 5 min) |
| `error` | `Printing order <n> failed permanently after <k> attempt(s): …` | retries used up after about 30 minutes, the receipt was **not** printed |
| `critical` | `Error thrown while handling message …PrintOpenOrdersCommand…` | poll failed (Shopware or its API unreachable), repeats every 10 s |
| `warning` | `Heartbeat: printer not reachable: …` | once a minute while the heartbeat pushes `down` |

Search in OpenObserve, stream `docker_logs`:

```sql
SELECT _timestamp, level, msg FROM docker_logs
WHERE container LIKE '%order-printer%' AND level <> 'info'
ORDER BY _timestamp DESC
```

### Alerts

| Alert | Rule | Check |
| --- | --- | --- |
| `order_printer_failed_jobs` | an `error` line with "failed permanently" from any order-printer container | every 5 min, window 5 min |
| `order_printer_shopware_unreachable` | more than 30 failed polls (5 minutes' worth) in 10 min | every 10 min, window 10 min |
| `order_printer_quiet_evening` | not a single "printed" line from 18:00 to 21:00 on Friday and Saturday | Fri and Sat 21:00 Europe/Berlin |

The alert definitions live in the infra repository (`docs/openobserve-alerts.md`).

## What to do

**Kuma: "Drucker aus oder abgesteckt".** The Pi answers but the printer is gone. Call the
restaurant: printer switched on, USB cable in, paper loaded. Orders are not lost: they stay
`open` in Shopware and are printed once the printer is back (each job retries for about 30
minutes, afterwards the next poll queues it again).

**Kuma: "Pi-Gateway nicht erreichbar".** Check the Pi in the Tailscale admin console
(`printer-<restaurant>`, last seen). If it is offline: power and network at the restaurant
(unplug the Pi for 10 seconds, it comes back on its own). If it is online, check the bridge:
`ssh lv@printer-<restaurant> systemctl status printer-bridge`.

**Kuma: down without a message (no heartbeat).** The instance is not running or cannot reach
Kuma. Check the service in Dokploy and the container logs; `docker exec <container> supervisorctl
status` shows whether `scheduler` and `worker` run.

**OpenObserve: `order_printer_failed_jobs`.** A receipt was not printed for 30 minutes. The
order is still `open` in Shopware and is queued again by the next poll, so fix the printer first
(see above), then tell the restaurant which order number to check. Inspect the job:

```bash
docker exec <container> su-exec app php bin/console messenger:failed:show
```

**OpenObserve: `order_printer_shopware_unreachable`.** The shop backend or its Admin API
integration is broken (URL, credentials, role). Nothing is printed, nothing is lost. Check the
shop's Kuma monitors first; `critical` lines in the logs carry the HTTP error.

**OpenObserve: `order_printer_quiet_evening`.** No receipt on a Friday or Saturday evening
usually means orders do not reach the printer: check the heartbeat monitor, then whether orders
are coming in at all (Shopware Admin, storefront monitors).
