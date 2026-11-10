#!/bin/bash
# bedrock-ddos-shield — NIC tuning for packet floods.
#
# EN: Maximize RX ring buffers and spread RX softirq across CPUs (RPS) so the
#     backlog doesn't overflow during a pps spike (which would drop real players
#     along with the flood). Pairs with sysctl/99-bds-net.conf.
# PT: Maximiza os ring buffers de RX e espalha o softirq de RX nas CPUs (RPS) pra
#     o backlog nao estourar num pico de pps (senao cai jogador junto com o flood).
#     Anda junto com sysctl/99-bds-net.conf.

CONF=/etc/bedrock-ddos-shield.conf
[ -r "$CONF" ] && . "$CONF"
: "${IFACE:=eth0}"
: "${IFACE_VF:=}"
: "${RPS_CPUS:=3}"

for IF in $IFACE $IFACE_VF; do
  [ -e "/sys/class/net/$IF" ] || continue
  MAX=$(ethtool -g "$IF" 2>/dev/null | awk '/^RX:/{print $2; exit}')
  [ -n "$MAX" ] && ethtool -G "$IF" rx "$MAX" 2>/dev/null
  for q in /sys/class/net/"$IF"/queues/rx-*; do
    [ -e "$q/rps_cpus" ] && echo "$RPS_CPUS" > "$q/rps_cpus" 2>/dev/null
  done
done
