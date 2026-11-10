#!/bin/bash
# bedrock-ddos-shield — periodic attack report (runs from a 5-min timer).
#
# EN: Sends a Discord embed ONLY while an attack is actually live. "Live" = more
#     than ATTACK_DELTA malicious packets dropped in a 3s sample (~1000 pps of
#     junk). Measured from the firewall counter, not raw traffic, so legit
#     players never trigger a false report.
# PT: Manda um embed no Discord SO enquanto houver ataque de verdade. "Ataque" =
#     mais de ATTACK_DELTA pacotes maliciosos dropados em 3s. Medido pelo contador
#     do firewall, nao pelo trafego total — jogador legitimo nunca dispara.
set -u

CONF=/etc/bedrock-ddos-shield.conf
[ -r "$CONF" ] && . "$CONF"
: "${IFACE:=eth0}"
: "${SERVER_NAME:=Bedrock Server}"
: "${ATTACK_DELTA:=3000}"
: "${DISCORD_WEBHOOK:=}"
[ -n "$DISCORD_WEBHOOK" ] || exit 0

mal(){ iptables -t raw -L BDSIN -n -v -x 2>/dev/null | awk '/bdsblock/{print $1; exit}'; }

D0=$(mal); R1=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
read sd0 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)
sleep 3
D1=$(mal); R2=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
read sd1 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)

DELTA=$(( D1 - D0 ))
[ "$DELTA" -lt "$ATTACK_DELTA" ] && exit 0   # no live attack → stay silent

CID=$(docker ps -q | head -1)
clean(){ sed -E 's/\x1b\[[0-9;]*m//g'; }
ONLINE=$(docker logs --tail 4 "$CID" 2>&1 | clean | grep -oE 'Online [0-9]+/[0-9]+' | tail -1); [ -z "$ONLINE" ] && ONLINE='Online ?'
TPS=$(docker logs --tail 4 "$CID" 2>&1 | clean | grep -oE 'TPS [0-9]+' | tail -1); [ -z "$TPS" ] && TPS='TPS ?'
PPS=$(( (R2-R1)/3 )); MALPPS=$(( DELTA/3 )); SDROP=$(( sd1 - sd0 ))
BLOCKED=$(ipset list bdsblock | grep -c timeout)
DROPPED=$(mal)
if [ "$SDROP" -gt 0 ]; then
  COLOR=15158332; HEALTH='⚠️ dropping packets ('"$SDROP"') — defense at its limit'
else
  COLOR=15844367; HEALTH='✅ 0 loss — defense holding'
fi

JSON='{"embeds":[{"title":"⚠️ DDoS attack in progress — '"$SERVER_NAME"'","color":'"$COLOR"',"fields":[{"name":"🎮 Server","value":"**'"$ONLINE"'** · **'"$TPS"'/20**","inline":false},{"name":"💥 Total flood","value":"'"$PPS"' pps","inline":true},{"name":"🔥 Junk blocked","value":"'"$MALPPS"' pps","inline":true},{"name":"🚫 Blocked IPs","value":"'"$BLOCKED"'","inline":true},{"name":"🧱 Total dropped","value":"'"$DROPPED"'","inline":true},{"name":"📉 Defense status","value":"'"$HEALTH"'","inline":false}],"footer":{"text":"Auto report every 5 min while the attack lasts"}}]}'
# URL via stdin (-K -) so the secret webhook never appears in argv / ps.
printf 'url = "%s"\n' "$DISCORD_WEBHOOK" \
  | curl -s -o /dev/null -m 10 -H "Content-Type: application/json" -d "$JSON" -K -
