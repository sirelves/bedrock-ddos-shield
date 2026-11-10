#!/bin/bash
# bedrock-ddos-shield — autoblocker.
#
# EN: Tails the server (Docker) logs and mirrors every IP PocketMine marks as
#     "Bad packet from <ip>" into the kernel ipset blocklist. The hashlimit
#     already stops new flooders; this makes the block sticky (BLOCK_TIMEOUT).
# PT: Le os logs do servidor e joga todo IP marcado como "Bad packet from <ip>"
#     na blocklist do kernel (ipset). O hashlimit ja barra floodadores novos;
#     isto torna o bloqueio persistente (BLOCK_TIMEOUT).
#
# Not using Docker? Replace the `docker logs` line with a tail of your server log.
set -u

CONF=/etc/bedrock-ddos-shield.conf
[ -r "$CONF" ] && . "$CONF"
: "${BLOCK_TIMEOUT:=3600}"

ipset create bdsblock hash:ip timeout "$BLOCK_TIMEOUT" maxelem 100000 -exist

while true; do
  CID=$(docker ps -q | head -1); [ -z "$CID" ] && { sleep 5; continue; }
  docker logs -f --since 2s "$CID" 2>&1 \
    | stdbuf -oL grep -aoE "Bad packet from [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+" \
    | while read -r _ _ _ ip; do
        ipset add bdsblock "$ip" timeout "$BLOCK_TIMEOUT" -exist
      done
  sleep 2
done
