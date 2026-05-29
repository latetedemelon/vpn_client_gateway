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
* `tests/test_vpn_backend.php`: **32/32 pass** (hostname validation, backend
  selection, OpenVPN rewrite incl. NordVPN cert naming, WireGuard `[Peer]`
  rewrite + `# Server` marker, peer lookup against the real generated list,
  end-to-end render).
* `vpn_update.sh` was run against the **live NordVPN API**; output validated
  with `xmllint`; all emitted flag codes map to existing SVGs (0 missing);
  UK→GB handled.

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

`master` and `claude/blissful-cerf-n2sG6` were identical before this work
(`git diff` empty), so there was nothing to merge between them. All changes here
are committed on `claude/blissful-cerf-n2sG6` and opened as a PR.
