#!/usr/bin/env bash
set -euo pipefail

# C-Net Audio Meet - Oracle Always Free E2.1.Micro bootstrap
# Run as root on a fresh Ubuntu instance. Secrets remain on the VPS.

DOMAIN="livekit.meet.mciedu.com"
INSTALL_DIR="/opt/cnet-meet-livekit"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl openssl docker.io docker-compose-v2 ufw
systemctl enable --now docker

PUBLIC_IP="$(curl -4fsS --max-time 10 https://ifconfig.me)"
API_KEY="cnet_$(openssl rand -hex 8)"
API_SECRET="$(openssl rand -hex 32)"
HOST_KEY="$(openssl rand -hex 24)"

# The 1 GB micro instance needs swap to avoid installation/runtime OOM failures.
if ! swapon --show | grep -q '^'; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

install -d -m 700 "$INSTALL_DIR"
cd "$INSTALL_DIR"

cat > livekit.micro.yaml <<EOF
port: 7880
bind_addresses: ["0.0.0.0"]
rtc:
  tcp_port: 7881
  port_range_start: 50000
  port_range_end: 50100
  use_external_ip: true
keys:
  ${API_KEY}: ${API_SECRET}
room:
  auto_create: true
  empty_timeout: 300
  departure_timeout: 20
  max_participants: 1000
logging:
  level: info
EOF

cat > Caddyfile <<EOF
${DOMAIN} {
  reverse_proxy 127.0.0.1:7880
  encode zstd gzip
}
EOF

cp "$SCRIPT_DIR/docker-compose.micro.yml" ./docker-compose.yml

cat > portal-config.php <<EOF
<?php
return [
    'livekit_url' => 'wss://${DOMAIN}',
    'livekit_api_key' => '${API_KEY}',
    'livekit_api_secret' => '${API_SECRET}',
    'host_access_key' => '${HOST_KEY}',
    'max_participants' => 1000,
    'token_ttl_seconds' => 21600,
];
EOF
chmod 600 livekit.micro.yaml portal-config.php

ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 7881/tcp
ufw allow 50000:50100/udp
ufw --force enable

cat >/etc/systemd/system/cnet-meet-firewall.service <<'UNIT'
[Unit]
Description=C-Net Meet host firewall rules
After=network-online.target
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c '/usr/sbin/iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || /usr/sbin/iptables -I INPUT 1 -p tcp --dport 80 -j ACCEPT; /usr/sbin/iptables -C INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || /usr/sbin/iptables -I INPUT 1 -p tcp --dport 443 -j ACCEPT; /usr/sbin/iptables -C INPUT -p tcp --dport 7881 -j ACCEPT 2>/dev/null || /usr/sbin/iptables -I INPUT 1 -p tcp --dport 7881 -j ACCEPT; /usr/sbin/iptables -C INPUT -p udp --dport 50000:50100 -j ACCEPT 2>/dev/null || /usr/sbin/iptables -I INPUT 1 -p udp --dport 50000:50100 -j ACCEPT'
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now cnet-meet-firewall.service

docker compose pull
docker compose up -d

cat > INSTALL-RESULT.txt <<EOF
C-NET LIVEKIT MICRO INSTALL COMPLETE
Domain: ${DOMAIN}
Public IP: ${PUBLIC_IP}
Portal config: ${INSTALL_DIR}/portal-config.php
LiveKit config: ${INSTALL_DIR}/livekit.micro.yaml
Capacity profile: testing, maximum 20 participants configured
EOF
chmod 600 INSTALL-RESULT.txt

docker compose ps
echo "Installation complete. Credentials were saved locally in ${INSTALL_DIR}/portal-config.php"
