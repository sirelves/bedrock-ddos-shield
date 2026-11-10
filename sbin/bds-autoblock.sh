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
# Space-separated IPs that must NEVER be auto-blocked (admin boxes, etc).
: "${BLOCK_ALLOWLIST:=}"

ipset create bdsblock hash:ip timeout "$BLOCK_TIMEOUT" maxelem 100000 -exist

# Only block a real, routable, public IPv4. Rejects malformed octets and skips
# loopback / RFC1918 private / link-local ranges and anything on the allowlist,
# so a poisoned log line can't make us block our own admins or private hosts.
valid_public_ip(){
  local ip="$1" o a
  IFS=. read -r -a o <<< "$ip"
  [ "${#o[@]}" -eq 4 ] || return 1
  for a in "${o[@]}"; do
    case "$a" in ''|*[!0-9]*) return 1 ;; esac   # digits only
    case "$a" in 0?*) return 1 ;; esac           # no leading zero (octal/decimal ambiguity)
    [ "$a" -le 255 ] || return 1
  done
  case "$ip" in
    0.*|127.*|10.*|169.254.*|192.168.*|255.255.255.255) return 1 ;;
    172.1[6-9].*|172.2[0-9].*|172.3[0-1].*)              return 1 ;;
  esac
  for a in $BLOCK_ALLOWLIST; do [ "$ip" = "$a" ] && return 1; done
  return 0
}

while true; do
  CID=$(docker ps -q | head -1); [ -z "$CID" ] && { sleep 5; continue; }
  # 1) strip ANSI/charset escapes.
  # 2) split each line on the FIRST "]: " — the logger's own message boundary,
  #    which only the server emits. The real message lands in field $2; any "]:"
  #    a player types in chat/commands pushes their text into $3+, so it can't
  #    masquerade as the system "Bad packet from" line. Require $2 to START with
  #    it, then take the 4th word as the IP.
  # 3) validate (public IPv4, no leading zeros, allowlist) before blocking.
  docker logs -f --since 2s "$CID" 2>&1 \
    | stdbuf -oL sed -E 's/\x1b\[[0-9;]*m//g; s/\x1b\([AB]//g' \
    | stdbuf -oL awk -F']: ' '
        $2 ~ /^Bad packet from [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/ {
          split($2, f, " "); print f[4]; fflush()
        }' \
    | while read -r ip; do
        valid_public_ip "$ip" && ipset add bdsblock "$ip" timeout "$BLOCK_TIMEOUT" -exist
      done
  sleep 2
done
