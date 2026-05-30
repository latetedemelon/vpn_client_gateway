# Running the gateway in a VM, LXC, or Docker

The gateway drives `wg`/`iptables` via `sudo`, so it needs `NET_ADMIN`, a tunnel
device, IP forwarding, and a way for LAN devices to route through it. How that
maps to each environment:

| Target | Verdict | Key requirements |
|---|---|---|
| **VM** (KVM/Proxmox/cloud) | ✅ like bare metal | own kernel; just enable `net.ipv4.ip_forward` |
| **LXC** (Proxmox/system container) | ⚠️ needs host prep | host WireGuard module + `/dev/net/tun` passthrough + NET_ADMIN (privileged, or unprivileged with the right config) |
| **Docker/Podman** | ⚠️ purpose-built image | `--cap-add=NET_ADMIN`, `--device /dev/net/tun`, `--sysctl net.ipv4.ip_forward=1`, host/macvlan networking, host WireGuard module (or userspace) |

The repo now includes the wiring for these: configurable LAN/WAN interfaces
(`/etc/vpngw/interfaces.conf`), container detection that skips host-only boot
steps (`is_container()`), and a Docker image under `docker/`.

## VM

A VM has its own kernel, so it behaves exactly like a Raspberry Pi install.
Follow the normal setup, then ensure forwarding is on:

```bash
echo 'net.ipv4.ip_forward=1' | sudo tee /etc/sysctl.d/99-vpngw.conf
sudo sysctl --system
```

Give the VM either two NICs (LAN + WAN) and set `/etc/vpngw/interfaces.conf`, or
one NIC that both serves the LAN and reaches the internet.

## LXC (Proxmox / system container)

Because the container shares the host kernel:

1. **Host:** ensure WireGuard is available (`modprobe wireguard`) — the container
   cannot load modules.
2. **Container config** (Proxmox `/etc/pve/lxc/<id>.conf`), allow the tun device
   and NET_ADMIN. For a privileged container:
   ```
   lxc.cgroup2.devices.allow: c 10:200 rwm
   lxc.mount.entry: /dev/net/tun dev/net/tun none bind,create=file
   features: nesting=1
   ```
   Unprivileged containers also work if the same device/caps are granted, but
   some hardened setups block tun creation or the OUTPUT-policy kill switch.
3. `net.ipv4.ip_forward` is per-namespace, so set it inside the container as in
   the VM section.
4. Install normally (the setup scripts detect systemd/OpenRC).

## Docker / Podman

An image is provided in `docker/`. Build and run from the repo root:

```bash
docker compose -f docker/docker-compose.yml up -d --build
# put your WireGuard config at ./docker/wireguard/wg0.conf first
```

Equivalent `docker run`:

```bash
docker run -d --name vpngw \
  --cap-add=NET_ADMIN \
  --device /dev/net/tun \
  --sysctl net.ipv4.ip_forward=1 \
  --network host \
  -v "$PWD/docker/wireguard:/etc/wireguard" \
  -v vpngw-etc:/etc/vpngw \
  yourrepo/vpn-client-gateway
```

Notes and caveats:

- **WireGuard module** must be present on the **host** (containers share the
  kernel). Otherwise bundle a userspace implementation (wireguard-go / boringtun)
  in the image.
- **Networking:** `--network host` is the simplest way to be a gateway for other
  LAN devices — but then the firewall/kill switch operates on the **host's**
  netfilter. For an isolated L2 presence, use a **macvlan** network and point
  clients at the container's macvlan IP.
- **Persistence:** the container manages the tunnel/firewall at start via
  `docker/entrypoint.sh`; there is no systemd inside, so boot-enable and
  `iptables-save`/`lbu` steps are skipped (`is_container()`).
- **Auth:** put the management UI behind authentication (`/etc/vpngw/auth.conf`,
  see `documentation/authentication.md`) before exposing it — with `--network
  host` the UI is reachable on the host's `:80`.
