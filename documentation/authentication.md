# Management-page authentication

The management web page can switch servers, toggle the VPN, reboot and shut down
the gateway, so it should not be left open on an untrusted LAN. Authentication is
**standards-based** and **off by default** (so upgrades never lock anyone out);
enable it via `/etc/vpngw/auth.conf`.

```
mode = off | basic | proxy
```

The guard (`www/vpnmgmt/auth.php`) is enforced by every web entry point.

## `basic` — HTTP Basic auth (RFC 7617)

Works with every browser and password manager, and with most IdPs that can
inject Basic credentials. The password is stored only as a bcrypt hash
(`password_hash()` / `password_verify()`).

```bash
sudo ./setup/set-admin-password.sh admin      # prompts for the password
```

That writes:

```ini
mode=basic
username=admin
password_hash=$2y$10$....bcrypt....
realm=VPN Client Gateway
```

> Serve the page over HTTPS (or behind a TLS-terminating reverse proxy) so Basic
> credentials aren't sent in clear text.

## `proxy` — trust an upstream SSO / reverse proxy

If you already run an identity-aware proxy, terminate auth there and let the
gateway trust the authenticated-user header it injects. Compatible with
**Authelia, Authentik, oauth2-proxy, Cloudflare Access, Tailscale Serve, nginx
`auth_request`, Traefik `forwardAuth`, Caddy `forward_auth`**, etc.

```ini
mode=proxy
user_header=Remote-User           # whatever your proxy sets (Remote-User, X-Forwarded-User, …)
trusted_proxies=127.0.0.1,::1     # REQUIRED: only accept the header from these REMOTE_ADDRs
```

`trusted_proxies` is **required** in proxy mode: if it is unset the gateway
**fails closed** (denies every request), because otherwise any client could
authenticate by simply sending the user header itself. Set it to your proxy's
address(es), and strip the header at the proxy so clients can't supply it.

Example (nginx in front of the gateway):

```nginx
location / {
    auth_request /auth;                       # Authelia/oauth2-proxy/etc.
    auth_request_set $user $upstream_http_remote_user;
    proxy_set_header Remote-User $user;
    proxy_pass http://127.0.0.1:80;
}
```

## Disabling

Set `mode=off` (or remove `/etc/vpngw/auth.conf`). See
`documentation/examples/auth.conf` for a template.
