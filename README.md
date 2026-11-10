# bedrock-ddos-shield

**Host-side mitigation for RakNet/UDP packet floods against Minecraft Bedrock servers** (PocketMine-MP, Nukkit, BDS) on Linux — no extra hardware, no proxy, just `iptables` + `ipset` + a little kernel tuning and a Discord watchdog.

Built and battle-tested on a live server under a real botnet flood: it absorbed a sustained **~110k–177k packets/second** attack with **0 softnet packet loss** and the box sitting at **>90% idle CPU**, dropping **~4 billion** junk packets without kicking a single legit player.

> 🇧🇷 **Versão em português mais abaixo** → [Português](#-português)

---

## The problem

Minecraft Bedrock uses **RakNet over UDP (port 19132)**. Booters/botnets flood that port with malformed or spoofed UDP packets (`Bad packet / Unknown IP address version` in your logs). Even when the server survives, the packet rate overflows the kernel's per-CPU input backlog and **real players time out and drop** — classic "everyone lagged out at once".

## How it works

Five small pieces, all driven by one config file:

```
UDP:19132  ──▶  iptables RAW PREROUTING  ──▶  [ BDSIN chain ]
                (before conntrack =                │
                 cheapest drop)                    ├─ 1. src in ipset blocklist?      → DROP
                                                    ├─ 2. this IP over 200 pps?        → DROP  ← main defense
                                                    └─ 3. otherwise (legit player)     → RETURN ──▶ server
```

| Piece | What it does |
|---|---|
| **`bds-firewall.sh`** | Builds the `raw`-table `BDSIN` chain: ipset blocklist drop + **per-IP hashlimit** (a real client does <100 pps; anything over `RATE_LIMIT` is dropped). Dropping in `raw` PREROUTING happens *before* conntrack — the cheapest possible drop. |
| **`bds-autoblock.sh`** | Tails the server log; every IP flagged `Bad packet from X` is mirrored into the `ipset` blocklist with a timeout, making the block sticky. |
| **`bds-nettune.sh`** + `99-bds-net.conf` | Maxes the NIC RX ring buffer, spreads RX softirq across CPUs (RPS), and raises `netdev_max_backlog`/`budget` so a pps spike doesn't overflow the queue and drop players with the flood. |
| **`bds-watch.sh`** | Watchdog. Sends a Discord alert **only on state transitions** (flood crossed threshold / softnet started dropping / back to normal / anti-cheat combat-kick regression) — no spam. |
| **`bds-attack-report.sh`** (+timer) | Every 5 min, posts a Discord status embed **only while an attack is actually live** (measured from the firewall's malicious-drop counter, so legit players never trigger it). |

## Requirements

- Linux with `systemd`, `iptables` (+`xtables` hashlimit), `ipset`, `ethtool`, `curl`, `coreutils` (`stdbuf`).
- Your Bedrock server reachable via `docker ps` (Pterodactyl/wings, plain Docker, …). **Not on Docker?** See [`docs/not-docker.md`](docs/not-docker.md) — it's a one-line change.
- Root.

## Install

```bash
git clone https://github.com/sirelves/bedrock-ddos-shield.git
cd bedrock-ddos-shield
sudo ./install.sh

# then edit your settings and apply
sudo nano /etc/bedrock-ddos-shield.conf
sudo systemctl restart bds-firewall bds-watch
```

Minimum you should set in `/etc/bedrock-ddos-shield.conf`: `GAME_PORT`, `IFACE` (check with `ip -br link`), `SERVER_NAME`, and `DISCORD_WEBHOOK` (optional). Full reference: [`docs/configuration.md`](docs/configuration.md).

## Verify it's working

```bash
# live counters — "pkts" on the hashlimit/blocklist rows climbing = junk being dropped
sudo iptables -t raw -L BDSIN -n -v -x

# packets/sec hitting the NIC right now
I=eth0; R1=$(cat /sys/class/net/$I/statistics/rx_packets); sleep 2; \
R2=$(cat /sys/class/net/$I/statistics/rx_packets); echo "rx pps = $(((R2-R1)/2))"

# softnet drops — the number that matters. Stays flat = you are NOT losing packets.
awk '{d+=strtonum("0x"$2)} END{print "softnet drops:", d}' /proc/net/softnet_stat
```

## ⚠️ Limitations — read this

This is **host-side** defense. It stops **packet-rate (pps) floods** brilliantly because the NIC still receives the bandwidth and the kernel drops the junk cheaply. It will **NOT** save you from a true **volumetric (Gbit) flood** that saturates your uplink *before* traffic reaches your kernel — no host-side rule can, because the pipe is already full upstream. For that you need **upstream/edge protection** (cloud DDoS protection, an Anycast proxy/scrubbing in front, or an ISP that null-routes). This project buys you survival against the common booter-grade pps floods, and clear visibility into when you've outgrown it.

Also recommended alongside: firewall off your SSH/panel ports from the public internet, and don't expose the raw server IP.

## Tuning notes

Defaults are deliberately conservative (`RATE_LIMIT=200` pps/IP). Lower it and you risk clipping legit players on bad mobile connections; raise it and you let more junk through per source. Measure with the counters above before changing. More in [`docs/configuration.md`](docs/configuration.md).

## License

MIT — see [LICENSE](LICENSE). Use it, fork it, no warranty.

---

## 🇧🇷 Português

**Mitigação, do lado do host, contra floods de pacotes RakNet/UDP em servidores Minecraft Bedrock** (PocketMine-MP, Nukkit, BDS) no Linux — sem hardware extra, sem proxy: só `iptables` + `ipset`, um tuning de kernel e um watchdog no Discord.

Feito e testado em servidor real sob ataque de botnet: absorveu um flood sustentado de **~110k–177k pacotes/segundo** com **0 perda de pacote no softnet** e a máquina a **>90% de CPU ociosa**, dropando **~4 bilhões** de pacotes de lixo sem derrubar um jogador legítimo sequer.

### O problema

Bedrock usa **RakNet sobre UDP (porta 19132)**. Booters/botnets inundam essa porta com pacotes UDP malformados ou spoofados (`Bad packet / Unknown IP address version` no log). Mesmo o servidor sobrevivendo, a taxa de pacotes estoura o backlog de entrada do kernel e **os jogadores reais caem por timeout** — o clássico "todo mundo lagou e caiu junto".

### Como funciona

Cinco peças pequenas, todas controladas por um único arquivo de config:

```
UDP:19132  ──▶  iptables RAW PREROUTING  ──▶  [ chain BDSIN ]
                (antes do conntrack =              │
                 drop mais barato)                 ├─ 1. IP está na blocklist ipset?  → DROP
                                                   ├─ 2. esse IP passou de 200 pps?   → DROP  ← defesa principal
                                                   └─ 3. senão (jogador legítimo)     → RETURN ──▶ servidor
```

| Peça | O que faz |
|---|---|
| **`bds-firewall.sh`** | Monta a chain `BDSIN` na tabela `raw`: drop da blocklist ipset + **hashlimit por IP** (cliente real faz <100 pps; o que passar de `RATE_LIMIT` é dropado). Dropar no `raw` PREROUTING acontece *antes* do conntrack — o drop mais barato possível. |
| **`bds-autoblock.sh`** | Lê o log do servidor; todo IP marcado `Bad packet from X` vai pra blocklist `ipset` com timeout, deixando o bloqueio persistente. |
| **`bds-nettune.sh`** + `99-bds-net.conf` | Maximiza o ring buffer de RX da placa, espalha o softirq de RX nas CPUs (RPS) e sobe `netdev_max_backlog`/`budget` pra um pico de pps não estourar a fila e derrubar jogador junto com o flood. |
| **`bds-watch.sh`** | Watchdog. Manda alerta no Discord **só na transição de estado** (flood passou do limite / softnet começou a dropar / normalizou / regressão do kick de combate do anticheat) — sem spam. |
| **`bds-attack-report.sh`** (+timer) | A cada 5 min, posta um embed de status no Discord **só enquanto houver ataque de verdade** (medido pelo contador de drops maliciosos do firewall, então jogador legítimo nunca dispara). |

### Requisitos

- Linux com `systemd`, `iptables` (+ hashlimit), `ipset`, `ethtool`, `curl`, `coreutils` (`stdbuf`).
- Servidor Bedrock acessível via `docker ps` (Pterodactyl/wings, Docker puro, …). **Não usa Docker?** Veja [`docs/not-docker.md`](docs/not-docker.md) — é uma linha só.
- Root.

### Instalação

```bash
git clone https://github.com/sirelves/bedrock-ddos-shield.git
cd bedrock-ddos-shield
sudo ./install.sh

# depois edite e aplique
sudo nano /etc/bedrock-ddos-shield.conf
sudo systemctl restart bds-firewall bds-watch
```

O mínimo a configurar: `GAME_PORT`, `IFACE` (veja com `ip -br link`), `SERVER_NAME` e `DISCORD_WEBHOOK` (opcional). Referência completa: [`docs/configuration.md`](docs/configuration.md).

### Conferir se está funcionando

```bash
# contadores ao vivo — "pkts" subindo nas linhas de hashlimit/blocklist = lixo sendo dropado
sudo iptables -t raw -L BDSIN -n -v -x

# pacotes/segundo chegando na placa agora
I=eth0; R1=$(cat /sys/class/net/$I/statistics/rx_packets); sleep 2; \
R2=$(cat /sys/class/net/$I/statistics/rx_packets); echo "rx pps = $(((R2-R1)/2))"

# softnet drops — o número que importa. Parado = você NÃO está perdendo pacote.
awk '{d+=strtonum("0x"$2)} END{print "softnet drops:", d}' /proc/net/softnet_stat
```

### ⚠️ Limitações — leia isto

Esta é defesa **do lado do host**. Ela segura **flood de taxa de pacotes (pps)** muito bem, porque a placa ainda recebe a banda e o kernel dropa o lixo barato. Ela **NÃO** salva de um **flood volumétrico (Gbit)** de verdade que satura seu link *antes* do tráfego chegar no kernel — nenhuma regra no host salva disso, porque o cano já encheu lá em cima. Pra isso você precisa de **proteção na borda/upstream** (proteção DDoS do provedor, proxy Anycast/scrubbing na frente, ou ISP que faça null-route). Este projeto te dá sobrevivência contra os floods de pps nível booter (os mais comuns) e visibilidade clara de quando você passou do ponto que ele aguenta.

Recomendado junto: feche as portas de SSH/painel da internet pública e não exponha o IP cru do servidor.

### Licença

MIT — veja [LICENSE](LICENSE). Use, forke, sem garantia.
