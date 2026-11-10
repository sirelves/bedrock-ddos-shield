#!/bin/bash
# bedrock-ddos-shield — watchdog.
#
# EN: Fires a Discord alert on STATE TRANSITIONS only (no spam):
#       A) a combat/anti-cheat "NetworkStackLatency timeout" kick reappears
#       B) flood crosses PPS_CRIT, or softnet starts dropping packets (defense
#          at its limit), or things go back to normal.
# PT: Dispara alerta no Discord so na TRANSICAO de estado (sem spam):
#       A) volta o kick de combate/anticheat "NetworkStackLatency timeout"
#       B) flood passa de PPS_CRIT, ou softnet comeca a dropar (defesa no limite),
#          ou normaliza.
set -u

CONF=/etc/bedrock-ddos-shield.conf
[ -r "$CONF" ] && . "$CONF"
: "${IFACE:=eth0}"
: "${SERVER_NAME:=Bedrock Server}"
: "${PPS_CRIT:=150000}"
: "${DISCORD_WEBHOOK:=}"

LOG=/var/log/bds-watch.log
state=ok
clean(){ sed -E 's/\x1b\[[0-9;]*m//g'; }

alert(){
  local msg="$1"
  echo "$(date '+%F %T') $msg" >> "$LOG"
  logger -t bds-watch -- "$msg"
  [ -n "$DISCORD_WEBHOOK" ] || return 0

  # color + title by severity
  local color title
  case "$msg" in
    *CRITICAL*|*regressed*|*NSL*) color=15158332; title='🚨 CRITICAL' ;;
    *flood*)                      color=15844367; title='⚠️ Strong flood' ;;
    *normal*)                     color=5763719;  title='✅ Back to normal' ;;
    *started*)                    color=3447003;  title='ℹ️ Watchdog started' ;;
    *)                            color=5793266;  title='ℹ️ Notice' ;;
  esac

  # live snapshot
  local CID ONLINE TPS R1 R2 s0 s1 PPS SDROP BLOCKED DEF
  CID=$(docker ps -q | head -1)
  ONLINE=$(docker logs --tail 4 "$CID" 2>&1 | clean | grep -oE 'Online [0-9]+/[0-9]+' | tail -1); [ -z "$ONLINE" ] && ONLINE='Online ?'
  TPS=$(docker logs --tail 4 "$CID" 2>&1 | clean | grep -oE 'TPS [0-9]+' | tail -1); [ -z "$TPS" ] && TPS='TPS ?'
  R1=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
  read s0 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)
  sleep 2
  R2=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
  read s1 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)
  PPS=$(( (R2-R1)/2 )); SDROP=$(( s1-s0 ))
  BLOCKED=$(ipset list bdsblock 2>/dev/null | grep -c timeout)
  if [ "$SDROP" -gt 0 ]; then DEF='🔴 dropping packets ('"$SDROP"')'; else DEF='🟢 0 loss, holding'; fi
  msg=${msg//\"/}

  local JSON='{"embeds":[{"title":"'"$title"'","description":"'"$msg"'","color":'"$color"',"fields":[{"name":"🎮 Server","value":"'"$ONLINE"' · '"$TPS"'/20","inline":true},{"name":"💥 Flood","value":"'"$PPS"' pps","inline":true},{"name":"🚫 Blocked IPs","value":"'"$BLOCKED"'","inline":true},{"name":"📉 Defense","value":"'"$DEF"'","inline":false}],"footer":{"text":"'"$SERVER_NAME"' watchdog · '"$(date '+%H:%M:%S')"'"}}]}'
  curl -s -o /dev/null -m 10 -H "Content-Type: application/json" -d "$JSON" "$DISCORD_WEBHOOK"
}

alert "watchdog started"

# loop A: combat / anti-cheat NSL kick regression
(
  while true; do
    CID=$(docker ps -q | head -1); [ -z "$CID" ] && { sleep 5; continue; }
    docker logs -f --since 2s "$CID" 2>&1 | stdbuf -oL grep -aE "NetworkStackLatency timeout" \
      | while read -r _; do alert "Player dropped by combat/NSL again (anti-cheat may have regressed)"; sleep 30; done
    sleep 2
  done
) &

# loop B: flood / softnet
R0=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
read d0 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)
while true; do
  sleep 10
  R1=$(cat /sys/class/net/"$IFACE"/statistics/rx_packets)
  read d1 _ < <(awk '{d+=strtonum("0x"$2)} END{print d}' /proc/net/softnet_stat)
  pps=$(( (R1-R0)/10 )); drop=$(( d1-d0 )); R0=$R1; d0=$d1
  if [ "$drop" -gt 0 ]; then
    [ "$state" != crit ] && alert "CRITICAL: packet loss (softnet +$drop in 10s, pps=$pps) — defense at its limit, players may drop"
    state=crit
  elif [ "$pps" -gt "$PPS_CRIT" ]; then
    [ "$state" != high ] && alert "flood: $pps pps (above $PPS_CRIT) but no loss — holding"
    state=high
  else
    [ "$state" != ok ] && alert "normal: pps=$pps, no packet loss"
    state=ok
  fi
done
