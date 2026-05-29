# NordVPN + WireGuard (NordLynx) Setup

This guide explains how to run the VPN Client Gateway with **NordVPN over
WireGuard** (NordVPN calls its WireGuard implementation **NordLynx**).

WireGuard is dramatically faster and lighter than OpenVPN — a great fit for a
Raspberry Pi gateway. The original OpenVPN backend still works exactly as
before; WireGuard is an additional backend you can opt into.

---

## How it works

The gateway is backend-agnostic. A small selector file decides which VPN
technology is active:

```
/etc/vpngw/backend     # contains "wireguard" or "openvpn"
```

| Concern              | OpenVPN                         | WireGuard (NordLynx)              |
|----------------------|---------------------------------|----------------------------------|
| Config file          | `/etc/openvpn/server.conf`      | `/etc/wireguard/wg0.conf`        |
| Interface            | `tun0` / `tun+`                  | `wg0` / `wg+`                     |
| Service              | `openvpn`                       | `wg-quick@wg0`                    |
| Switch server        | rewrite `remote` line, restart  | rewrite `[Peer]`, `wg-quick` down/up |
| Outbound port        | 1194/1197/1198 udp              | 51820 udp                        |

When you click a country flag in the web UI, the gateway looks up that
server's **WireGuard public key** and **endpoint** (embedded in
`vpnservers.xml`), rewrites the `[Peer]` section of `wg0.conf`, and restarts
the tunnel. No network round-trip to NordVPN is needed to switch — the keys
are already on disk.

The `[Interface]` section (your private key, address and DNS) is preserved on
every switch; only the `[Peer]` and a `# Server = <hostname>` marker change.

---

## Prerequisites

* A working VPN Client Gateway (see the project
  [wiki Installation Guide](https://github.com/mr-canoehead/vpn_client_gateway/wiki))
  — i.e. the web UI is served and the web user (`www-data`) can run
  `iptables`/`service` via passwordless `sudo`.
* An active **NordVPN** subscription.
* Internet access on the Pi during setup.

---

## Quick start

From a checkout of this repository on the gateway:

```bash
sudo ./setup/nordvpn-wireguard-setup.sh
```

The script will:

1. Install `wireguard-tools`, `curl`, `jq` (and `openresolv` for DNS).
2. Ask for your **NordVPN access token** and fetch your NordLynx private key.
3. Pick a recommended WireGuard server and write `/etc/wireguard/wg0.conf`.
4. Set the backend to `wireguard` (`/etc/vpngw/backend`).
5. Generate the server list (`vpnservers.xml`) and deploy it to the web dir.
6. Enable the tunnel at boot.

Then configure the firewall / kill switch and bring the tunnel up:

```bash
cd fw && sudo ./fw-config
sudo wg-quick up wg0
```

Open the web UI and pick a country by clicking its flag.

### Getting a NordVPN access token

1. Log in at <https://my.nordaccount.com>.
2. Go to **NordVPN → Set up NordVPN manually** (or *API / Access token*).
3. **Generate new token** and copy it.

The token is used once to fetch your NordLynx private key from
`https://api.nordvpn.com/v1/users/services/credentials`; it is **not** stored.

> Don't want to use a token? You can paste a NordLynx private key directly when
> prompted. On any machine with the NordVPN app connected via NordLynx:
> `sudo wg show nordlynx private-key`.

---

## Switching servers / refreshing the list

The server catalogue (and the per-server public keys) change over time. Refresh
the list periodically with the provider updater:

```bash
sudo VPNMGMT_DIR=/var/www/vpnmgmt \
  ./www/vpnmgmt/vpn_providers/nordvpn/vpn_update.sh
```

Useful knobs (environment variables):

| Variable          | Default                                   | Meaning                                  |
|-------------------|-------------------------------------------|------------------------------------------|
| `MAX_PER_COUNTRY` | `4`                                       | servers listed per country (full list)   |
| `BASIC_CODES`     | `US GB CA DE NL FR SE CH AU JP SG ES IT NO` | ISO codes shown on the **Basic** flag grid |
| `VPNMGMT_DIR`     | *(unset)*                                 | also deploy the result here              |

To refresh automatically, add a cron job (e.g. weekly):

```cron
0 4 * * 0 root VPNMGMT_DIR=/var/www/vpnmgmt /path/to/repo/www/vpnmgmt/vpn_providers/nordvpn/vpn_update.sh >/var/log/vpn_update.log 2>&1
```

---

## The firewall / kill switch

`fw/fw-template` (and the hardened `fw/fw-harden`) now allow WireGuard:

* outbound `udp/51820` on `eth0` (the handshake/data),
* forwarding and NAT for the `wg+` interface family, and
* the kill switch covers `wg0` just like `tun0` — if the tunnel drops, the
  default `DROP` policy blocks LAN traffic from leaking to your ISP.

`enablevpn.php` / `disablevpn.php` automatically NAT and forward over the
**active** backend's interface, so the same Admin → Enable/Disable buttons work
for WireGuard.

---

## Verifying

```bash
sudo wg show                       # handshake + transfer counters
curl https://api.nordvpn.com/v1/helpers/ips/insights | jq   # confirms NordVPN exit IP/country
```

In the web UI, **Tools → Get IP address geolocation** should show the country
of the server you selected.

---

## Troubleshooting

**`wg-quick up wg0` fails with `resolvconf: command not found`.**
Install a resolvconf provider: `sudo apt-get install openresolv`. Alternatively
remove the `DNS = ...` line from `/etc/wireguard/wg0.conf` and manage DNS
yourself.

**Running Pi-hole on the gateway?**
The `DNS =` line sets the *host* resolver to NordVPN's servers, which can
conflict with Pi-hole. Either remove the `DNS =` line from `wg0.conf` and set
Pi-hole's upstream DNS to `103.86.96.100` / `103.86.99.100`, or leave it and
point Pi-hole at the system resolver. Make sure your chosen DNS path still goes
through the tunnel to avoid leaks.

**The flag/name doesn't appear after switching.**
The server isn't in `vpnservers.xml` (or the list is stale). Re-run
`vpn_update.sh`. The tunnel still works; only the label is missing.

**Switch does nothing / "could not switch".**
The selected server has no public key/endpoint in `vpnservers.xml`. Refresh the
list with `vpn_update.sh`.

**No internet through the gateway, kill switch seems stuck.**
Confirm the tunnel is up (`sudo wg show`) and that `fw-config` was run. With the
kill switch armed, LAN traffic is intentionally blocked whenever `wg0` is down.

---

## Switching back to OpenVPN

```bash
echo openvpn | sudo tee /etc/vpngw/backend
sudo wg-quick down wg0
sudo systemctl disable wg-quick@wg0
```

(Or simply delete `/etc/vpngw/backend` — the gateway then auto-detects OpenVPN
when `/etc/openvpn/server.conf` exists.)
