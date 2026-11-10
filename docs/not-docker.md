# Not running on Docker? / Não usa Docker?

Two scripts read the server log through `docker logs`: **`bds-autoblock.sh`** and **`bds-watch.sh`** (and `bds-attack-report.sh` reads the player count the same way). If your Bedrock/PocketMine server isn't in a container, point them at your log file instead.

## The change

In **`sbin/bds-autoblock.sh`**, replace the Docker block:

```bash
CID=$(docker ps -q | head -1); [ -z "$CID" ] && { sleep 5; continue; }
docker logs -f --since 2s "$CID" 2>&1 \
```

with a tail of your server log (adjust the path):

```bash
tail -Fn0 /path/to/pocketmine/server.log 2>&1 \
```

Do the same wherever `docker logs -f` appears in **`bds-watch.sh`**.

For the **player count / TPS** snapshot lines in `bds-watch.sh` and `bds-attack-report.sh` (they grep `Online X/Y` and `TPS N`), swap `docker logs --tail 4 "$CID"` for `tail -n 20 /path/to/server.log`. If your software prints a different status line, adjust the `grep -oE` pattern to match it.

## The firewall + tuning need no change

`bds-firewall.sh`, `bds-nettune.sh` and the sysctl file are pure kernel/network — they work identically with or without Docker. Only the log-reading parts are container-specific.
