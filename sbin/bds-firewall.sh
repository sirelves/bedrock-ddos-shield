#!/bin/bash
# bedrock-ddos-shield — install the iptables raw-table filter for the game port.
#
# EN: Drops flood packets in the raw PREROUTING table, BEFORE conntrack, which is
#     the cheapest place to drop. Two gates: (1) the ipset blocklist, (2) a per-IP
#     hashlimit. Legit traffic RETURNs untouched.
# PT: Dropa o flood na tabela raw PREROUTING, ANTES do conntrack (lugar mais barato).
#     Dois portoes: (1) blocklist ipset, (2) hashlimit por IP. Trafego bom passa.
set -u

CONF=/etc/bedrock-ddos-shield.conf
[ -r "$CONF" ] && . "$CONF"
: "${GAME_PORT:=19132}"
: "${RATE_LIMIT:=200}"
: "${RATE_BURST:=400}"
: "${BLOCK_TIMEOUT:=3600}"

# Blocklist set (IPs flagged by the autoblocker). Idempotent.
ipset create bdsblock hash:ip timeout "$BLOCK_TIMEOUT" maxelem 100000 -exist

# (Re)build the BDSIN chain from scratch.
iptables -t raw -N BDSIN 2>/dev/null || true
iptables -t raw -F BDSIN

# 1) Drop anything from a blocked IP.
iptables -t raw -A BDSIN -m set --match-set bdsblock src -j DROP

# 2) Drop any source IP exceeding RATE_LIMIT pps (the main defense).
iptables -t raw -A BDSIN -m hashlimit \
  --hashlimit-mode srcip --hashlimit-name bdsrl \
  --hashlimit-above "${RATE_LIMIT}/sec" --hashlimit-burst "$RATE_BURST" \
  --hashlimit-htable-expire 10000 -j DROP

# 3) Everything else is a legit player — let it through.
iptables -t raw -A BDSIN -j RETURN

# Hook the chain onto the game UDP port (idempotent).
iptables -t raw -C PREROUTING -p udp --dport "$GAME_PORT" -j BDSIN 2>/dev/null \
  || iptables -t raw -I PREROUTING -p udp --dport "$GAME_PORT" -j BDSIN
