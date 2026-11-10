#!/bin/bash
# bedrock-ddos-shield installer. Run as root from the repo root:  sudo ./install.sh
set -euo pipefail

[ "$(id -u)" = 0 ] || { echo "Please run as root (sudo ./install.sh)"; exit 1; }
cd "$(dirname "$0")"

echo "==> Checking dependencies"
missing=0
for b in iptables ipset ethtool docker curl stdbuf; do
  if ! command -v "$b" >/dev/null 2>&1; then echo "   MISSING: $b"; missing=1; fi
done
[ "$missing" = 1 ] && echo "   Install the missing packages (e.g. apt install iptables ipset ethtool coreutils) and re-run."

echo "==> Installing scripts to /usr/local/sbin"
install -m 0755 sbin/bds-firewall.sh sbin/bds-autoblock.sh sbin/bds-nettune.sh \
                sbin/bds-watch.sh sbin/bds-attack-report.sh /usr/local/sbin/

echo "==> Installing systemd units"
install -m 0644 systemd/bds-firewall.service systemd/bds-nettune.service \
                systemd/bds-watch.service systemd/bds-attack-report.service \
                systemd/bds-attack-report.timer /etc/systemd/system/

echo "==> Installing sysctl tuning"
install -m 0644 sysctl/99-bds-net.conf /etc/sysctl.d/
sysctl --system >/dev/null

echo "==> Installing config (kept if it already exists)"
# 0600 root:root — the config holds your Discord webhook; keep it non-readable
# to other local users.
if [ -f /etc/bedrock-ddos-shield.conf ]; then
  chmod 0600 /etc/bedrock-ddos-shield.conf
  chown root:root /etc/bedrock-ddos-shield.conf 2>/dev/null || true
  echo "   /etc/bedrock-ddos-shield.conf already present — left untouched (perms set to 0600)."
else
  install -m 0600 -o root -g root bedrock-ddos-shield.conf.example /etc/bedrock-ddos-shield.conf
  echo "   Created /etc/bedrock-ddos-shield.conf — EDIT IT before relying on alerts."
fi

echo "==> Enabling services"
systemctl daemon-reload
systemctl enable --now bds-nettune.service bds-firewall.service \
                       bds-watch.service bds-attack-report.timer

echo
echo "Done. Next steps:"
echo "  1) edit  /etc/bedrock-ddos-shield.conf  (GAME_PORT, IFACE, SERVER_NAME, DISCORD_WEBHOOK)"
echo "  2) apply: systemctl restart bds-firewall bds-watch"
echo "  3) watch: iptables -t raw -L BDSIN -n -v -x"
