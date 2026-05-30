# Security audit

Review of the web app, privilege model, firewall, auth/leak/watchdog code, and
the container image. Severity, location and status for each finding. "Fixed"
items shipped in the same change as this document; "Open" items are tracked
follow-ups.

## Findings

| ID | Sev | Finding | Status |
|----|-----|---------|--------|
| H1 | High | CSRF on destructive actions (reboot/shutdown/disable-VPN/switch) — GET, no token | **Open** |
| H2 | High | Web tier is root-equivalent via broad `sudo` (`su`, `cat`, `install`) | **Open** |
| H3 | High | jQuery loaded over plaintext HTTP (MITM/supply-chain); admin UI is HTTP-only | **Fixed** — jQuery vendored locally (`www/js/jquery-3.7.1.min.js`, SRI-verified) |
| H4 | High | Container ships unauthenticated control UI on `--network host` | **Mitigated** — entrypoint prints a loud "auth is OFF" warning; docs push auth-on |
| M1 | Medium | Proxy/SSO auth bypass when `trusted_proxies` is unset (header spoofing) | **Fixed** — proxy mode now fails closed if `trusted_proxies` is unset |
| M2 | Medium | Reflected XSS in `iplocation.php` (unescaped external data over HTTP) | **Fixed** — all output HTML-escaped; lookups use HTTPS + TLS verify; IP validated |
| M3 | Medium | Container DNS leak — `wg-quick` `DNS=` dropped by the no-op resolvconf shim | **Open** (documented; use `force_dns` / explicit resolver) |
| M4 | Medium | `auth.conf` bcrypt hash world-readable (0644) | **Fixed** — `set-admin-password.sh` writes `0640 root:<web-group>` |
| L1 | Low | Unescaped service name in `util.php` sudo strings (not web-reachable) | Open (defense-in-depth) |
| L2 | Low | No brute-force throttling/lockout on Basic auth | Open |
| L3 | Low | XPath built from `servername` in `choosevpn*` (semi-trusted list) | Open |
| L4 | Low | Container hardening: cap-drop / no-new-privileges / digest-pin | **Partly fixed** — compose now `cap_drop: ALL` + `NET_ADMIN,NET_BIND_SERVICE`; `no-new-privileges` deferred (conflicts with the H2 sudo model) |
| L5 | Low | `syslog.php` exposes logs (behind auth) | Open (acceptable) |

## Already solid

Hostname allow-listing before XPath/shell (`vpn_valid_hostname`); `escapeshellarg`
on dynamic shell args (no web-reachable command injection found); constant-time
auth comparisons (`hash_equals`/`password_verify`); sanitized `WWW-Authenticate`
realm; fail-closed kill switch + IPv6 leak-block; provider-name validation (no
path traversal); `wg0.conf` `0600`; opt-in container firewall; PHP 8.2 disables
XXE by default; security logic covered by unit tests.

## Recommended next steps (Open highs)

- **H1 (CSRF):** move mutating endpoints to POST + a per-session CSRF token (or a
  required custom header), add `SameSite=Strict`. Forced VPN-disable via CSRF is
  a deanonymization risk, so this is the top remaining item.
- **H2 (de-root the web tier):** replace the broad sudoers (drop `su`/`cat`/
  `install`) with small root-owned, argument-less wrapper scripts (persist rules,
  read wg conf, apply firewall); then `no-new-privileges` can be enabled in the
  container (L4).
