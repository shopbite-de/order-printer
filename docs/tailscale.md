# Tailscale network for remote printing

The central deployment (see [docker.md](docker.md)) prints from a container on the Dokploy
host to a Raspberry Pi in the restaurant over Tailscale. Everything that takes part, the
Dokploy host, every Pi and the admin laptops, is in one tailnet. This document describes how
that tailnet is set up; the policy it uses is [tailscale-acl.json](tailscale-acl.json).

```
Dokploy host (tag:server) ──tcp/9100──▶ Pi (tag:printer) ──USB──▶ receipt printer
admin laptop (group:admins) ──tcp/22 (Tailscale SSH), tcp/9100──▶ Pi
```

## Plan and login

Tailscale's Free plan is for personal use only, so the tailnet runs on the **Standard** plan.
Sign up with a business identity on the `shopbite.de` domain, not a personal account; the
tailnet then belongs to the organisation and other admins can be added under the same domain.

Billing is per user: every person who logs in counts, the Pis and the Dokploy host do not,
because they are joined as tagged devices and tagged devices are not users.

## Tags

| Tag           | Devices                          | Owner            |
| ------------- | -------------------------------- | ---------------- |
| `tag:server`  | the Dokploy host                 | `group:admins` |
| `tag:printer` | every Pi gateway (one per shop)  | `group:admins` |

A tagged device has no user identity, so the ACL is the only thing that decides what it may
reach. Both tags are owned by the admins, which also means only admins can create auth keys
carrying these tags.

## Policy

Upload `tailscale-acl.json` in the admin console under **Access controls**, replacing the
default policy (the default allows everything to talk to everything). The file is plain JSON
so it can be validated with `jq`; the admin console also accepts it unchanged.

What it allows:

- `tag:server` → `tag:printer` on port 9100 only. The order-printer containers can reach the
  printers and nothing else on the Pis.
- admins → `tag:printer` on ports 22 and 9100, for Tailscale SSH and `nc` test prints.
- admins → `tag:server` on port 22.

Everything else is denied, in particular:

- Pis cannot reach each other, the Dokploy host, or any admin device. A Pi only talks to the
  coordination server, which is not subject to the ACL.
- The Dokploy host cannot SSH into a Pi. A compromised container on the host gets the printer
  port and nothing more.

The `tests` and `sshTests` sections are evaluated by Tailscale every time the policy is saved;
a change that opens or closes something unintentionally is rejected before it takes effect.

### Tailscale SSH

The `ssh` rule lets admins log in to the Pis and the Dokploy host as any non-root user or as
`root`, with `action: check`: the first login from a device asks the admin to re-authenticate in
the browser, then logins from that device work without a prompt for 12 hours. No SSH keys are
distributed to the Pis; access is revoked by removing the user from the tailnet.

Tailscale SSH must also be enabled on the device (`tailscale up --ssh`, which the Pi setup
script in `dev-ops/pi-gateway/` does) and the ACL must allow port 22 from the same source,
which the second grant does.

```bash
tailscale ssh pi@printer-<shop>
```

## Auth key for the Pi image

The Pi setup script joins the tailnet non-interactively with a pre-authorized, tagged key so
that a new Pi needs no admin approval and lands with the right tag.

Admin console → **Settings → Keys → Generate auth key**:

| Setting        | Value                                                       |
| -------------- | ----------------------------------------------------------- |
| Description    | `pi-gateway`                                                |
| Reusable       | yes                                                         |
| Expiration     | 90 days (the maximum)                                       |
| Ephemeral      | no (the Pi must keep its identity across reboots)           |
| Pre-authorized | yes                                                         |
| Tags           | `tag:printer`                                               |

Store the key in the password manager together with its expiry date, and note the date in the
calendar: after 90 days the key stops working for *new* Pis, existing Pis are unaffected. A
device joined with a tagged auth key has key expiry disabled automatically, so the Pis never
need to re-authenticate; check the **Key expiry** column in the machine list to confirm.

Never commit the key. The setup script reads it from `TS_AUTHKEY`.

## Joining the Dokploy host

On `panel.shopbite.de`:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh --advertise-tags=tag:server --hostname=dokploy
```

`tailscale up` prints a login URL; open it as an admin. Because the host advertises a tag owned
by the admins, the device ends up tagged instead of being tied to the admin's user account.
Then in the machine list: **Disable key expiry** for the host, so an expired node key can never
take every restaurant offline at once. Verify with `tailscale status`: the host should show
`tag:server` and no user.

Tailscale does not touch Docker's networking, and Dokploy's Traefik keeps serving on the
public interface; the tailnet only adds the `tailscale0` interface with a `100.64.0.0/10`
address.

## Verifying that containers reach the tailnet

Containers on the Dokploy host use Docker's bridge (or, for Swarm services, `docker_gwbridge`)
and leave the host through the `MASQUERADE` rule Docker installs for every container network.
That rule is not bound to an interface, so packets to a `100.x.y.z` address are routed via
`tailscale0` and source-NATed to the host's tailnet IP, and the Pi sees a connection from
`tag:server`. This needs to be verified once on the host, with at least one Pi online:

```bash
PI=$(tailscale ip -4 printer-<shop>)

# 1. from the host itself: the ACL lets tag:server reach 9100 and nothing else
nc -zv -w 3 $PI 9100      # succeeded
nc -zv -w 3 $PI 22        # timed out (blocked by the ACL, not by the Pi)

# 2. from a throw-away container on the default bridge
docker run --rm alpine sh -c "nc -zv -w 3 $PI 9100 && nc -zv -w 3 $PI 22"
# 9100 open, 22 timed out

# 3. from a container on a user-defined network, the way Dokploy runs services
docker network create ts-test
docker run --rm --network ts-test alpine nc -zv -w 3 $PI 9100
docker network rm ts-test
```

If 1 works but 2 or 3 does not, the usual causes are:

- `ip_forward` off: `sysctl net.ipv4.ip_forward` must be `1` (Docker sets it on start).
- A firewall dropping forwarded traffic: `iptables -L FORWARD -v -n` should show Docker's
  `DOCKER-USER`/`DOCKER-FORWARD` chains before any `DROP`; with ufw, `DEFAULT_FORWARD_POLICY`
  must not be `DROP` for `docker0` → `tailscale0`.
- Missing masquerade: `iptables -t nat -S POSTROUTING | grep MASQUERADE` must list the
  container subnet without an `-o` restriction.

Only after a successful test set `PRINTER_DSN=tcp://<pi-ip>:9100` on the Dokploy service and
run `printer:test` in its container.

### Fallback: Tailscale sidecar per stack

If the host cannot route container traffic into the tailnet, each order-printer stack can
carry its own Tailscale node and the app container shares its network namespace. The sidecar
needs kernel networking (`/dev/net/tun`, `NET_ADMIN`) because the PHP connector opens plain
TCP sockets and cannot use the SOCKS proxy that userspace mode offers.

```yaml
services:
  tailscale:
    image: tailscale/tailscale:latest
    hostname: order-printer-<shop>
    environment:
      TS_AUTHKEY: ${TS_AUTHKEY}          # reusable key with tag:server
      TS_STATE_DIR: /var/lib/tailscale
      TS_USERSPACE: "false"
      TS_EXTRA_ARGS: --advertise-tags=tag:server
    volumes:
      - tailscale-state:/var/lib/tailscale
    devices:
      - /dev/net/tun:/dev/net/tun
    cap_add:
      - NET_ADMIN
    restart: unless-stopped

  order-printer:
    build: .
    network_mode: service:tailscale
    depends_on:
      - tailscale
    environment:
      PRINTER_DSN: tcp://<pi-ip>:9100
      # ... SHOPWARE_*, APP_SECRET as in compose.yaml
    volumes:
      - order-printer-data:/app/data

volumes:
  tailscale-state:
  order-printer-data:
```

Each stack then appears as its own `tag:server` machine, which the ACL already covers. The
cost is one more container per restaurant and a second auth key (`tag:server`, reusable) in
the password manager.

## Adding an admin

Invite the person in the admin console with the **Admin** role and add their login to
`group:admins` in the policy. Only then can they SSH to the Pis and create tagged auth keys;
removing them from the group (or from the tailnet) revokes that everywhere at once.

The policy uses an explicit group instead of `autogroup:admin` because Tailscale's policy
tests cannot evaluate the autogroup, and a policy without working tests cannot be saved.
