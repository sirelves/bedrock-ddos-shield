#!/bin/bash
# bedrock-ddos-shield uninstaller. Run as root:  sudo ./uninstall.sh
# Keeps /etc/bedrock-ddos-shield.conf unless you pass --purge.
set -u

[ "$(id -u)" = 0 ] || { echo "Please run as root (sudo ./uninstall.sh)"; exit 1; }

echo "==> Stopping and disabling services"
systemctl disable --now bds-firewall.service bds-nettune.service \
                        bds-watch.service bds-attack-report.timer \
                        bds-attack-report.service 2>/dev/null || true

echo "==> Removing iptables hook + chain"
GAME_PORT=19132
[ -r /etc/bedrock-ddos-shield.conf ] && . /etc/bedrock-ddos-shield.conf
iptables -t raw -D PREROUTING -p udp --dport "$GAME_PORT" -j BDSIN 2>/dev/null || true
iptables -t raw -F BDSIN 2>/dev/null || true
iptables -t raw -X BDSIN 2>/dev/null || true
ipset destroy bdsblock 2>/dev/null || true

echo "==> Removing files"
rm -f /usr/local/sbin/bds-firewall.sh /usr/local/sbin/bds-autoblock.sh \
      /usr/local/sbin/bds-nettune.sh /usr/local/sbin/bds-watch.sh \
      /usr/local/sbin/bds-attack-report.sh
rm -f /etc/systemd/system/bds-firewall.service /etc/systemd/system/bds-nettune.service \
      /etc/systemd/system/bds-watch.service /etc/systemd/system/bds-attack-report.service \
      /etc/systemd/system/bds-attack-report.timer
rm -f /etc/sysctl.d/99-bds-net.conf
systemctl daemon-reload

if [ "${1:-}" = "--purge" ]; then
  rm -f /etc/bedrock-ddos-shield.conf
  echo "   Purged /etc/bedrock-ddos-shield.conf"
fi

echo "Done. (sysctl values revert on next reboot; run 'sysctl --system' to reload defaults now.)"
