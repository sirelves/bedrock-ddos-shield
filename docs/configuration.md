# Configuration / Configuração

All scripts read `/etc/bedrock-ddos-shield.conf`. After editing it:

```bash
sudo systemctl restart bds-firewall bds-watch
```

The attack-report timer and nettune pick up changes on their next run / reboot (or `sudo systemctl restart bds-nettune`).

| Key | Default | Meaning (EN) | Significado (PT-BR) |
|---|---|---|---|
| `GAME_PORT` | `19132` | UDP port the server listens on | Porta UDP do servidor |
| `IFACE` | `eth0` | NIC to read counters from (`ip -br link`) | Placa pra ler contadores |
| `IFACE_VF` | `ens1` | Optional SR-IOV VF NIC; empty if none | Placa VF SR-IOV opcional; vazio se não tiver |
| `SERVER_NAME` | `My Bedrock Server` | Name shown in Discord alerts | Nome nos alertas do Discord |
| `RATE_LIMIT` | `200` | Max pps per source IP before DROP | Máx. pps por IP antes do DROP |
| `RATE_BURST` | `400` | hashlimit burst allowance | Folga de burst do hashlimit |
| `BLOCK_TIMEOUT` | `3600` | Seconds a flooder IP stays blocked | Segundos que o IP fica bloqueado |
| `BLOCK_ALLOWLIST` | *(empty)* | IPs never to auto-block (space-sep) | IPs que nunca são bloqueados (sep. por espaço) |
| `DISCORD_WEBHOOK` | *(empty)* | Webhook URL; empty disables alerts | URL do webhook; vazio desliga alertas |
| `PPS_CRIT` | `150000` | pps the watchdog calls a strong flood | pps que o watchdog chama de flood forte |
| `ATTACK_DELTA` | `3000` | Malicious drops/3s that count as "attacked" | Drops maliciosos/3s que contam como "sob ataque" |
| `RPS_CPUS` | `3` | Hex CPU bitmask for RPS (`3`=CPU0+1) | Máscara hex de CPU pro RPS |

## Choosing `RATE_LIMIT`

A legitimate Bedrock client sends well under 100 pps. `200` gives ~2x headroom for jitter and burst. Guidance:

- **Clipping legit players on bad connections?** Raise toward `300–400`.
- **Want a tighter net** (more aggressive)? Lower toward `120–150`, and watch `iptables -t raw -L BDSIN -n -v -x` for the RETURN-row count to make sure you're not dropping good traffic.

Never tune blind — use the counters in the README's "Verify" section first.

## `RPS_CPUS` on bigger boxes

`RPS_CPUS` is a **hex CPU bitmask**: `3` = CPUs 0+1, `7` = 0–2, `f` = 0–3, `ff` = 0–7. Set it to cover the cores you want handling RX softirq. On a 2-vCPU box, `3` is correct.
