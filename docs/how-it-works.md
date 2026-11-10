# How it works (deep dive) / Como funciona (a fundo)

## Why the `raw` table

Linux processes an incoming packet through several `iptables` tables. `conntrack` (connection tracking) is relatively expensive and runs after the `raw` table. Under a flood, you want to drop junk **as early and as cheaply as possible** — so we hook the game port in **`raw` PREROUTING**, which runs *before* conntrack. A dropped flood packet never costs a conntrack entry.

```
packet in ──▶ raw/PREROUTING ──▶ conntrack ──▶ mangle ──▶ nat ──▶ filter ──▶ socket
              ▲ we drop here
              (cheapest, pre-conntrack)
```

## The two gates in `BDSIN`

1. **ipset blocklist (`bdsblock`)** — an O(1) hash set of IPs the autoblocker has caught saying `Bad packet`. Each entry has a timeout so the set self-cleans. A match = immediate DROP.

2. **per-IP hashlimit** — the workhorse. `hashlimit` keeps a per-source-IP token bucket in kernel space. A real Bedrock client sends <100 pps; once a single IP exceeds `RATE_LIMIT` (default 200) pps it gets dropped, while every other IP is unaffected. This is what absorbs a brand-new flood from IPs the blocklist has never seen.

Anything passing both gates hits `RETURN` and continues to the server untouched.

> During a real attack we observed the hashlimit doing the heavy lifting: the `ipset` naturally emptied, because the flood was dropped *before* PocketMine ever logged a `Bad packet`, so there was nothing left to auto-block. That's the system working as intended, not a failure.

## Why the net tuning matters

The kernel receives packets into a **per-CPU backlog queue** and drains it in softirq. Under a pps spike, if the queue (`netdev_max_backlog`) overflows or the softirq budget (`netdev_budget`) is too small, the kernel drops packets indiscriminately — **including your real players'**. That shows up as `dropped` in `/proc/net/softnet_stat` and as everyone timing out at once.

So we:
- raise `netdev_max_backlog` (32768) and `netdev_budget`/`budget_usecs` so each softirq poll clears far more packets;
- maximize the NIC **RX ring buffer** (`ethtool -G … rx <max>`) so the NIC holds more before handing off;
- enable **RPS** (`rps_cpus`) to spread RX processing across CPUs instead of pinning one core.

The target metric is simple: **softnet drops stay at 0 during the flood.** If they do, no legit player is lost to the network, no matter how high the pps.

## The watchdog's state machine

`bds-watch.sh` tracks one of three states — `ok` / `high` (flood but no loss) / `crit` (softnet dropping) — and only alerts when the state *changes*. That's the difference between a useful alert ("defense just hit its limit") and 500 identical messages. The 5-minute attack report is separate and stays completely silent unless the firewall's malicious-drop counter proves an attack is live right now.

---

## PT-BR — resumo

- **Tabela `raw`**: dropamos o lixo *antes* do conntrack (lugar mais barato); pacote dropado nem custa entrada de conntrack.
- **Dois portões no `BDSIN`**: (1) blocklist `ipset` O(1) com timeout; (2) **hashlimit por IP** — o cavalo de batalha, que barra floodador novo que a blocklist nunca viu. Jogador real faz <100 pps, o que passar de `RATE_LIMIT` cai.
- **Tuning de rede**: backlog maior + budget de softirq maior + ring buffer no máximo + RPS nas CPUs, pra o pico de pps não estourar a fila e **derrubar jogador junto**. Métrica-alvo: **softnet drop = 0** durante o flood.
- **Watchdog**: máquina de 3 estados (`ok`/`high`/`crit`), alerta só na mudança — sem spam. O relatório de 5 min fica mudo a menos que o contador do firewall prove ataque ao vivo.
