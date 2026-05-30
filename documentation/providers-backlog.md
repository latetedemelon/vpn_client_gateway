# VPN provider backlog & multi-vendor support

## How multi-vendor works today

The gateway is **vendor-agnostic** at the plumbing level:

- The active VPN technology is chosen by `/etc/vpngw/backend` (`openvpn` |
  `wireguard`).
- The active **provider** is named in `/etc/vpngw/provider` (default `nordvpn`)
  and maps to a directory under `www/vpnmgmt/vpn_providers/<name>/` holding that
  vendor's `server.conf`, `vpnservers.xml` and (optionally) a `vpn_update.sh`
  server-list updater. See `www/vpnmgmt/provider.php`.
- Server switching is fully generic: the UI writes the chosen server into the
  active backend's config (`vpn_backend.php`), looking up the WireGuard
  `pubkey`/`endpoint` from `vpnservers.xml`.

### Bringing in any WireGuard vendor right now

Any standards-compliant WireGuard config works **without provider-specific
code** via:

```bash
sudo ./setup/import-wireguard-config.sh /path/to/vendor.conf [CC] [Country]
```

This installs the config, selects the `wireguard` backend + `custom` provider,
and generates a one-server `vpnservers.xml` for the UI. Use it for Mullvad,
Proton, AirVPN, IVPN, a self-hosted peer, etc.

## Wired now

| Provider | Backend | Server list | Notes |
|---|---|---|---|
| **NordVPN** | WireGuard (NordLynx) | native `vpn_update.sh` (API) | recommended-server + per-server keys |
| NordVPN / PIA / PureVPN / Newshosting | OpenVPN | bundled `vpnservers.xml` | original backend |
| **custom** | WireGuard | generated on import | any vendor via `import-wireguard-config.sh` |

## Backlog (native, first-class support to add)

Each item means: a `vpn_providers/<name>/` driver + a `vpn_update.sh` that builds
`vpnservers.xml` (with WireGuard `pubkey`/`endpoint` + ISO `flagfile`), plus
credential/key handling in setup.

- [ ] **Mullvad** — public WireGuard relay list + REST API; key registration via
      account number. Per-relay pubkeys are published (clean fit).
- [ ] **Proton VPN** — WireGuard via the Proton API; per-config private keys
      generated in the dashboard; logical-server list with load/score.
- [ ] **AirVPN** — published WireGuard configs / "Config Generator" API.
- [ ] **IVPN** — public server list + WireGuard key registration API.
- [ ] **Surfshark** — WireGuard server list (per-key registration).
- [ ] **Windscribe / TorGuard / etc.** — config-import friendly; cover via the
      generic importer first, add native updaters by demand.
- [ ] **Self-hosted** (wg-easy, Algo, PiVPN, Tailscale exit nodes) — already
      works through the generic importer; could add a small helper.

## Cross-cutting follow-ups

- [ ] A web UI provider selector (reads `vpngw_known_providers()`).
- [ ] Multi-server lists for the `custom` provider (import a directory of confs).
- [ ] Per-provider DNS defaults (currently NordLynx DNS is the default).
