# Design: Per-client multi-exit VPN gateway

Status: **proposed** (design only — not yet implemented)
Scope: WireGuard / NordVPN (NordLynx). OpenVPN multi-exit is out of scope.

This builds on the WireGuard backend already in the gateway (`vpn_backend.php`,
the NordVPN server-list updater, the kill-switch firewall). It lets **different
LAN devices egress through different VPN endpoints at the same time** — e.g. the
TV in the US, a laptop in Germany, everything else on the default.

---

## 1. Summary

Run **several WireGuard tunnels at once**, one per *active country/exit*, and use
**source-based policy routing** (keyed to each device) to send a device's packets
into its assigned tunnel, with **per-tunnel NAT** and a **per-tunnel fail-closed
kill switch**. Devices wanting the same country are **pooled onto one shared
tunnel** rather than opening a new one. A self-service web page lets each device
choose its own exit.

A `mode` flag keeps today's single-tunnel behaviour as the default, so existing
installs are unaffected.

---

## 2. Decisions (agreed)

| Topic | Decision |
|---|---|
| Per-client UX | The page is **requester-aware**: it reads the device's IP, shows where *it* exits, and a flag click sets the exit for *that device only*. Plus an admin view for everyone. |
| Consolidation | **Pool by country.** If a tunnel to the requested country is already up, the device joins it (ref-counted). A new tunnel is opened only if that country isn't already active. |
| Device identity | **MAC is the source of truth**; IP is the volatile binding. On IP/MAC mismatch (DHCP change) we re-resolve by MAC and update the routing rule. IP-only fallback when no MAC is visible (off-segment clients). |
| Capacity | `max_connections` configurable, **default 4** (= max distinct simultaneous exits/countries). |
| IPv6 | **Out for Phase 1** (with a v6 leak-block so dual-stack devices can't bypass). **In at Phase 4.** |
| DNS | Auto-switch Pi-hole's upstream to NordVPN DNS whenever any exit is up; restore when all are down. True *per-country* DNS is Phase 3. |
| Leak testing | **Full leak testing in scope** — DNS + IPv4 + IPv6 + kill-switch, on demand (per-exit and per-client), with continuous monitoring and an optional self-hosted probe (§8). |

---

## 3. Concepts & data model

- **Exit** — a live tunnel bound to one country (pooled). `{ id, country_code, iface, server_hostname, pubkey, endpoint, table_id, clients:[mac,…], created_at, last_used }`. Server/pubkey/endpoint come from the existing `vpnservers.xml`.
- **Assignment** — `device(MAC) → country_code` (the desired exit). The engine maps the device's *current* IP to the exit's routing table.
- **Pooling rule** — assignments reference a **country**, not a specific tunnel; many devices → one exit per country.

Persisted as small JSON files; the engine reconciles the live system to them:

```
/etc/vpngw/mode                 "single" (default) | "multi"
/etc/vpngw/multi.conf           { "max_connections": 4,
                                  "default_country": "US" | "direct" | "block",
                                  "manage_pihole_dns": true,
                                  "idle_teardown_secs": 600 }
/etc/vpngw/assignments.json     { "aa:bb:cc:dd:ee:ff": "DE", "...": "US" }
/var/lib/vpngw/state.json       runtime: exits, ref-counts, mac↔ip, table ids
```

`assignments.json` is intent (by MAC); `state.json` is live truth (managed by the
engine). The web UI only edits intent + calls the reconcile script.

---

## 4. How a device chooses an exit (request flow)

1. Device opens the gateway page. PHP reads `REMOTE_ADDR`, resolves it to a MAC via
   the neighbour table (`ip neigh`). The page shows **"You (Living-room-TV) exit
   via 🇺🇸 United States"** and a flag grid.
2. Device clicks 🇩🇪. PHP validates, writes `{<mac>: "DE"}` into
   `assignments.json`, and invokes `vpngw-apply-exits` via sudo.
3. The engine reconciles (see §5): joins an existing DE tunnel or opens one,
   points this device's rule at it, tears down now-unused tunnels.
4. The page refreshes and shows 🇩🇪.

Admins get a second view listing all known devices (from DHCP leases / `ip neigh`)
with a per-device country dropdown, plus a status panel (per-exit up/down,
handshake age, ref-counts).

---

## 5. The reconcile engine (`vpngw-apply-exits`)

One idempotent root script is the *only* thing that mutates the system. On each
run it computes desired vs. live state and converges:

**a. Resolve devices.** Build `MAC → current IP` from `ip neigh` + DHCP leases.
Drop stale, add new. This is the IP↔MAC reconciliation.

**b. Determine required exits (pooling).** Collapse assignments to the **set of
distinct countries** currently wanted by at least one *present* device.
- If a required country has no live exit and we're **under `max_connections`**, create one (pick a recommended server for that country from `vpnservers.xml`).
- If at capacity: evict an exit with **zero clients** first; if none, **reject** the new country and surface a clear message (configurable: reject vs. LRU-evict an in-use exit).
- Exits with zero clients past `idle_teardown_secs` are torn down to free slots.

**c. Per-exit tunnel.** For each required exit, ensure `/etc/wireguard/<iface>.conf`
exists with `Table = off` (we own routing) and the right `[Peer]`, and the tunnel
is up (`wg-quick up <iface>`).

**d. Routing (rebuilt from scratch each run, in our own priority band).**
```sh
# per exit: a table with a fail-closed default
ip route replace default dev <iface> table <T>
ip route replace blackhole default table <T> metric 9999   # kill switch
# per present device assigned to that exit's country:
ip rule add from <device_ip> lookup <T> priority 1000
```
- **Kill switch:** the `blackhole` survives the tunnel's route vanishing when it
  drops, so a device on a dead exit is **dropped, never leaked**.
- **NAT:** `iptables -t nat -A POSTROUTING -o <iface> -j MASQUERADE` per exit.
- **rp_filter:** set loose (`net.ipv4.conf.<iface>.rp_filter = 2`) — multiple
  same-address tunnels need this or asymmetric returns are dropped.
- **IPv6 leak-block (P1):** `ip6tables` DROP forwarding for any device under
  management, so dual-stack devices can't bypass the v4 tunnel. Lifted in P4.

**e. DNS (Pi-hole).** If `manage_pihole_dns` and ≥1 exit is up, set Pi-hole
upstream to NordVPN DNS and restart FTL; when 0 exits are up, restore the saved
upstream (see §7).

The web layer never runs `ip`/`wg`/`iptables` directly — it edits JSON and calls
this script, exactly like the existing privileged-helper pattern.

---

## 6. Connection pooling & ref-counting (detail)

- An exit's `clients` set holds the **MACs** currently routed through it.
- Switching a device from DE→US: remove its MAC from the DE exit, add to US;
  if DE's set becomes empty, mark idle (torn down after `idle_teardown_secs`).
- `max_connections` caps the number of *exits*, not devices — 20 devices all
  wanting the US share one tunnel and one slot.
- Capacity policy is explicit and configurable (reject vs. evict) so behaviour at
  the 5th country is predictable.

---

## 7. Pi-hole upstream auto-switch

- On first exit up: back up current `PIHOLE_DNS_1/2` (from `setupVars.conf`) to
  `/var/lib/vpngw/pihole-dns.bak`, set them to `103.86.96.100` / `103.86.99.100`,
  `pihole restartdns`.
- On last exit down: restore from backup, `pihole restartdns`.
- Detect Pi-hole presence; no-op (with a log line) if absent. Opt-in via
  `manage_pihole_dns`.
- **Caveat:** Pi-hole's queries originate from the Pi, so they egress via the Pi's
  *own* default route → DNS geolocation reflects one country, not per-device.
  Phase 3 adds per-exit `dnsmasq`/`unbound` forwarders bound to each tunnel for
  true per-country DNS.

---

## 8. DNS leak testing (full)

Geo-spoofing is only as good as its leaks. The gateway includes **first-class
leak testing** covering DNS, IPv4, IPv6 and the kill switch — on demand and
(optionally) continuously, **per exit and per client**.

### What is checked
- **DNS leak:** the resolver(s) that actually answer are NordVPN's
  (`103.86.96.100/.99.100`) — **never** the ISP's resolver.
- **DNS egress path:** DNS packets leave via the tunnel interface, not `eth0`.
- **IP leak:** the public IP / country seen externally matches the exit's country
  and is a NordVPN address.
- **IPv6 leak:** no IPv6 egress at all in P1 (the v6 leak-block holds); in P4, v6
  DNS/IP must match the exit too.
- **Kill-switch (fail-closed):** with an exit's tunnel forced down, that exit's
  devices have data *and* DNS dropped — no fallback to the ISP or another exit.

### How it runs
- **Per-exit (gateway-run):** for each active exit, probe *through that exit*
  (bind to the interface, e.g. `curl --interface <iface>`, or run from the exit's
  routing table) and (1) hit an IP-insight endpoint to confirm country + Nord
  ownership, and (2) run a DNS-leak probe — resolve N randomized sub-labels of a
  wildcard test domain, then read back which resolver IPs queried it — asserting
  they are Nord-only.
- **Per-client (browser-assisted):** the self-service page offers **"Test my
  connection,"** which runs the probes *from the requesting device* so the result
  reflects that device's real path through its assigned exit, shown as a clear
  pass/fail card.
- **Kill-switch test:** an admin action forces an exit down, asserts egress + DNS
  are blocked, then restores it.

### Probe backends (configurable)
- **Third-party** DNS-leak service for convenience (e.g. a `bash.ws`-style JSON
  flow / ipleak), used on demand only.
- **Self-hosted (privacy mode):** an authoritative DNS responder + logger you run,
  so tests never touch third parties (optional, Phase 3).

### Continuous monitoring (optional, Phase 3)
Periodic per-exit checks; on a detected leak, **fail closed** — auto-disable the
leaking exit and flag it in the UI rather than leak silently — plus a syslog entry.

### Privacy note
Leak tests necessarily emit queries to an external checker; they run **on demand**
(or on a schedule you set), never silently, and the self-hosted option keeps them
fully in-house.

---

## 9. Web UI / backend fit

- Extends `vpn_backend.php` with a `mode` check: `single` → today's code path
  untouched; `multi` → delegates to a new `vpn_exits.php`.
- **Generation is pure functions** (wg config text, `ip rule`/route lists, NAT
  rules) so they're unit-testable exactly like the existing 36 tests; only thin
  wrappers shell out.
- New pages: self-service exit picker (requester-aware), admin device map, status.

---

## 10. Risks & open questions

1. **Shared `10.5.0.2` address (top risk, gates Phase 1).** NordVPN pins your
   key's server-side `AllowedIPs` to `10.5.0.2/32`, so every tunnel must source
   from `10.5.0.2` — i.e. the same /32 on multiple interfaces. Expected to work
   with `Table=off` + strict policy routing + per-interface MASQUERADE + loose
   `rp_filter`, but **must be validated on real hardware**. Different NordVPN
   tokens don't help (still pinned to 10.5.0.2). Fallback if the kernel/Nord
   refuses: per-exit **network namespaces** (heavier; each ns gets its own
   10.5.0.2 + veth). To be proven before committing to the routing approach.
2. **Off-segment clients** (behind another router) expose no MAC to the Pi → fall
   back to IP-only assignment for those.
3. **Capacity policy at the cap:** reject vs. LRU-evict — defaulting to
   *evict-zero-client-exits-then-reject*; configurable.
4. **Pi load** scales with active exits/handshakes; `max_connections=4` keeps it
   modest on a Pi.

---

## 11. Phases

- **Phase 1 — core engine (CLI/JSON):** pooled multi-tunnel, MAC↔IP resolution,
  source policy routing, per-exit NAT + fail-closed kill switch,
  `max_connections`, consolidation/ref-counting, IPv6 leak-block, and
  **automated per-exit leak checks (DNS/IP/kill-switch) as a CLI command**.
  *Resolves risk #1.*
- **Phase 2 — web UI:** requester-aware self-service page, admin device map,
  status; Pi-hole auto-switch toggle; **per-client "Test my connection" leak test
  and per-exit leak status in the UI**.
- **Phase 3 — DNS & niceties:** per-exit DNS forwarders (true per-country DNS),
  **continuous leak monitoring (fail-closed on detection) and an optional
  self-hosted leak-probe responder**, device discovery/labels, metrics.
- **Phase 4 — IPv6:** dual-stack exits (drop the v6 leak-block, add v6 tables/NAT,
  extend leak tests to v6 DNS/IP).

---

## 12. Testing (no hardware required for most)

- **Pure-function unit tests** for wg-config / `ip rule` / route / NAT generation,
  the pooling+ref-count state machine, and leak-probe response parsing (same style
  as `tests/test_vpn_backend.php`).
- **Network-namespace integration tests**: simulate several "client" namespaces +
  several tunnel namespaces on one Linux host to validate routing, pooling and
  kill-switch (no real Pi/account).
- **Leak-test validation in netns:** deliberately introduce a leak (a route or
  resolver that bypasses a tunnel) and assert the leak test flags it; assert the
  kill-switch test blocks both egress and DNS when a tunnel is down.
- **Hardware validation** reserved for risk #1 and live NordVPN connectivity.
