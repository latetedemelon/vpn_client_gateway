# VPN Client Gateway -- container image (Docker / Podman).
#
# Runtime requirements (see documentation/lxc-docker.md):
#   --cap-add=NET_ADMIN                      manage wg/iptables
#   --device /dev/net/tun                    tunnel device
#   --sysctl net.ipv4.ip_forward=1           routing
#   host networking (or macvlan)             so LAN devices can use it as a gateway
#   WireGuard module on the host             (or a userspace impl in the image)
#
# Mount your WireGuard config at /etc/wireguard/wg0.conf.
FROM debian:bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php php-cli php-xml php-curl \
        wireguard-tools iptables iproute2 openresolv \
        curl jq ca-certificates sudo procps iputils-ping \
 && rm -rf /var/lib/apt/lists/*

# Web UI, firewall scripts and setup helpers.
COPY www/   /var/www/html/
COPY fw/    /opt/vpngw/fw/
COPY setup/ /opt/vpngw/setup/

# Serve index.php (remove Apache's stock landing page that would shadow it) and
# quiet the "ServerName" startup warning.
RUN rm -f /var/www/html/index.html \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername >/dev/null 2>&1 || true

# The web user drives wg/iptables/etc via passwordless sudo (the gateway model).
RUN printf '%s\n' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/wg, /usr/bin/wg-quick, /sbin/iptables, /usr/sbin/iptables, /sbin/ip6tables, /usr/sbin/ip6tables, /sbin/iptables-save, /usr/sbin/iptables-save, /bin/cat, /usr/bin/install, /bin/su, /usr/sbin/service, /bin/systemctl, /usr/bin/systemctl' \
      > /etc/sudoers.d/vpngw \
 && chmod 440 /etc/sudoers.d/vpngw

COPY docker/entrypoint.sh /usr/local/bin/vpngw-entrypoint
RUN chmod +x /usr/local/bin/vpngw-entrypoint

EXPOSE 80
# Healthy as long as Apache answers (any HTTP status, including a 401 when auth
# is enabled, means the UI is up).
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -s -o /dev/null --max-time 5 http://localhost/ || exit 1
ENTRYPOINT ["/usr/local/bin/vpngw-entrypoint"]
