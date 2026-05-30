# Implementation notes — NordVPN + WireGuard support

This document records what was changed and **why**, so the work can be reviewed
later. It also lists the things that could not be verified end-to-end in the
build environment (no Raspberry Pi / no live tunnel) and how they were
de-risked instead.

## Goal

Make the VPN Client Gateway work with **NordVPN over WireGuard (NordLynx)**,
while keeping the existing OpenVPN backend and all current providers working.

## Key decisions

1. **Add WireGuard as a second backend instead of replacing OpenVPN.**
   Existing PIA/PureVPN/Newshosting/NordVPN-OpenVPN installs keep working. The
   active backend is chosen by `/etc/vpngw/backend` (`openvpn`/`wireguard`),
   with auto-detection fallback (prefers OpenVPN for backward compatibility).
   *Rationale:* lowest-risk, maximally useful, no breaking changes.

2. **Introduce a backend abstraction layer (`www/vpnmgmt/vpn_backend.php`).**
   All backend-specific behaviour (service control, interface name, switching
   servers, enable/disable + kill switch) lives here. The rest of the UI is now
   backend-agnostic. `manage_openvpn.php` became a thin compat shim that
   includes the new `manage_vpn.php` dispatcher.
   *Rationale:* keeps the UI/firewall code identical for both backends and makes
   the switching logic unit-testable.

3. **Embed the WireGuard public key + endpoint in `vpnservers.xml`.**
   The NordVPN API does not hand out static `.conf` files, and its exact-host
   filters are unreliable. Each server's `<pubkey>` and `<endpoint>` are baked
   into the server list by the updater, so switching servers needs **no**
   network round-trip — fast and offline-capable.
   *Rationale:* robust switching; the only network dependency is the periodic
   list refresh.

4. **Rewrite the NordVPN updater (`vpn_update.sh`) using the current API + jq.**
   The old updater used removed NordVPN endpoints and Python 2 / lxml /
   pycountry (all dead). The new one makes a single call to
   `…/v1/servers?filters[servers_technologies][identifier]=wireguard_udp` and
   builds the XML with `jq` (no Python). It also writes a `<flagfile>` ISO code
   per server so flags resolve directly.
   *Rationale:* the old path simply does not run anymore.

5. **NordLynx interface defaults:** `Address = 10.5.0.2/32`,
   `DNS = 103.86.96.100, 103.86.99.100`, `Endpoint …:51820`,
   `PersistentKeepalive = 25`. These match NordVPN's official NordLynx client.

6. **Privileged config writes.** `/etc/wireguard/wg0.conf` is root-owned 600.
   The web layer renders the new config to a temp file and installs it with
   `sudo install -m 600 -o root -g root …`, matching the project's existing
   "www-data has passwordless sudo" model (the same model the OpenVPN flow and
   `iptables` calls already rely on). Config rendering is split into pure
   functions (`vpn_render_*`) so it is testable without touching `/etc`.

7. **Kept the `?vpnserver=` web contract unchanged.** The flag links, Admin
   Enable/Disable buttons and AJAX endpoints all behave exactly as before; only
   the implementation underneath is backend-aware. Added input validation
   (`vpn_valid_hostname`) on the `$_GET` value before it touches xpath/files.

8. **Firewall:** `fw-template` and `fw-harden` now allow `udp/51820` and
   forward/NAT/accept the `wg+` interface family alongside `tun+`. The kill
   switch therefore covers WireGuard too.

## Files changed / added

Added:
* `www/vpnmgmt/vpn_backend.php` — backend abstraction (the core of this change).
* `www/vpnmgmt/manage_vpn.php` — backend-agnostic dispatcher.
* `setup/nordvpn-wireguard-setup.sh` — one-shot NordVPN+WireGuard setup.
* `tests/test_vpn_backend.php` — 32 unit tests for the new logic.
* `documentation/wireguard-nordvpn-setup.md` — user guide.
* `documentation/IMPLEMENTATION_NOTES.md` — this file.

Changed:
* `www/vpnmgmt/manage_openvpn.php` — now a compat shim → `manage_vpn.php`.
* `www/vpnmgmt/enablevpn.php`, `disablevpn.php` — call backend-aware
  `vpn_enable()`/`vpn_disable()`.
* `www/currentvpnsection.php`, `www/choosevpnbasicxml.php` — backend-aware
  current server + inline `<flagfile>` support.
* `www/index.php`, `www/index.css` — include the new dispatcher, backend-aware
  "VPN disabled" check, footer shows the active backend.
* `www/vpnmgmt/vpn_providers/nordvpn/vpn_update.sh` — modern API + jq rewrite.
* `www/vpnmgmt/vpn_providers/nordvpn/vpnservers.xml` — regenerated (WireGuard,
  408 servers across 136 countries, with embedded keys/endpoints).
* `fw/fw-template`, `fw/fw-harden` — WireGuard rules + kill switch.
* `README.md` — documents the two backends and links the guide.

## What was verified in CI/build env

* `php -l` clean on every PHP file.
* `bash -n` clean on every shell script.
* `tests/test_vpn_backend.php`: **36/36 pass** (hostname validation, backend
  selection, OpenVPN rewrite incl. NordVPN cert naming, WireGuard `[Peer]`
  rewrite + `# Server` marker, current-server parsing, peer lookup against the
  real generated list, end-to-end render).
* `vpn_update.sh` was run against the **live NordVPN API**; output validated
  with `xmllint`; all emitted flag codes map to existing SVGs (0 missing);
  UK→GB handled.
* A GitHub Actions workflow (`.github/workflows/ci.yml`) runs all of the above
  (via `tests/run.sh`) on every push/PR. It is **green** on this branch.

## Bug found and fixed during self-review

`/etc/wireguard/wg0.conf` is root-owned (mode 600). The first cut read it with
`file_get_contents()` running as the web user (`www-data`), which cannot read
it — this would have made the "current server" display and server switching
silently fail in production. Fixed to read via `sudo cat` (the same privileged
path used to write the file). Parsing was extracted into a tested pure helper.

## Not verifiable here (needs a Pi / live account) — de-risked how

* **NordLynx private-key retrieval** from an access token
  (`…/v1/users/services/credentials`). Could not test without a real token.
  *Mitigation:* the setup script also accepts a pasted private key and documents
  the `wg show nordlynx private-key` alternative, so it works regardless of API
  auth quirks.
* **`wg-quick up/down` + DNS (resolvconf).** *Mitigation:* `openresolv` is
  installed best-effort and DNS behaviour (incl. Pi-hole) is documented.
* **Live iptables/kill-switch behaviour.** Rules mirror the existing, working
  OpenVPN rules, generalised to `wg+`.

## Open questions for review

* Default `MAX_PER_COUNTRY=4` and the `BASIC_CODES` set are opinionated — easy
  to tune via env vars.
* Whether to also offer NordVPN-over-OpenVPN via the new API (kept out of scope;
  the user asked specifically for WireGuard).
* Whether to ship a periodic `cron`/timer for `vpn_update.sh` by default
  (currently documented, not installed automatically).

## Branch / merge status

* **PR #1 — NordVPN + WireGuard support** (everything documented above): merged
  into `master`.
* **PR #2 — multi-exit design doc** (`documentation/multi-exit-design.md`, design
  only, no code): merged into `master` after the QA below.
* `gh-pages` is the GitHub Pages site branch and is intentionally **not** merged
  into `master`.

## Multi-exit design doc (PR #2)

`documentation/multi-exit-design.md` captures the proposed design for running
several WireGuard tunnels at once so different LAN devices can egress through
different countries at the same time: source-based policy routing keyed per
device, per-tunnel NAT and a fail-closed kill switch, country-pooled and
ref-counted connections, MAC-based identity with IP↔MAC reconciliation,
`max_connections` (default 4), Pi-hole upstream DNS auto-switch, and full
DNS/IPv4/IPv6/kill-switch leak testing (per-exit and per-client). **Status:
proposed — no implementation yet.** The top risk to validate before any build is
the shared `10.5.0.2/32` source address across simultaneous NordLynx tunnels.

## QA before merge (2026-05-29)

* `tests/run.sh` locally: PHP lint **ok**, `bash -n` **ok**, `xmllint` **ok**,
  unit tests **36/36 PASS** (the `shellcheck` step is advisory and was skipped —
  not installed in this environment).
* GitHub Actions CI on PR #2: both check runs reported **success**.
* PR #2 is documentation-only, so the single-tunnel runtime behaviour is
  unchanged and there is no code regression surface.

## Throughput meter (live, on the management page)

The management page shows a live **Throughput** panel under "Current VPN server"
with per-interface RX/TX rates, each row tagged by role:

* **VPN tunnel** — the active VPN interface (`wg0` for WireGuard, `tun0` for
  OpenVPN, via `vpn_iface()`); shown as "VPN off" when the gateway is disabled.
* **WAN (outflow)** — the physical uplink to the internet, identified as the
  interface that holds the main-table default route (parsed from
  `/proc/net/route`; the VPN tunnel is ignored so we report the *physical* NIC
  even while the tunnel owns the effective default).
* **LAN (inflow)** — every other physical NIC (client-facing). On a multi-NIC
  box these are separate rows; on a single-NIC box there is just the one
  physical NIC, reported as the WAN/uplink (it carries LAN traffic too).

Virtual/bridge/container interfaces (`lo`, `veth*`, `docker*`, `br-*`, other
`tun*`/`wg*`, …) are excluded.

Key design points:

1. **No new privileges.** Counters come from `/proc/net/dev` and the route table
   from `/proc/net/route`, both world-readable, so the meter needs no `sudo`,
   touches no firewall/kill-switch state, and is independent of the
   WireGuard/OpenVPN backend plumbing.
2. **Rates are computed in the browser** from two successive samples. The
   endpoint (`www/vpnmgmt/throughput.php`) returns only the cumulative byte
   counters plus a server timestamp as JSON; the page polls every ~2 s and
   derives `Δbytes / Δt`. No server-side state and no blocking `sleep`.
3. **Robustness.** A negative delta (counter reset when an interface bounces) is
   suppressed; polling pauses while the browser tab is backgrounded
   (`document.hidden`); the first sample just primes the baseline.
4. **Testable core.** Parsing of `/proc/net/dev` and `/proc/net/route`, the
   physical-NIC filter and the WAN/LAN classification all live in pure functions
   (`www/vpnmgmt/netstat.php`) covered by `tests/test_netstat.php`.

Files: added `www/vpnmgmt/netstat.php`, `www/vpnmgmt/throughput.php`,
`tests/test_netstat.php`; edited `www/index.php` (panel + poller),
`www/index.css` (panel styles) and `tests/run.sh` (run the new tests).
