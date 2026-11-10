# BedrockShield (PocketMine-MP plugin)

The **application-layer** half of `bedrock-ddos-shield`, for when you **don't control the OS** (managed/shared hosting — only a plugins folder, no SSH/root). It can't touch `iptables`, so it defends at the layer it *can* reach: RakLib and the login flow.

> 🇧🇷 **Português abaixo.**

## What it does

| Mechanism | Effect |
|---|---|
| **Per-IP login rate limit** | More than N pre-login attempts from one IP in a window → the IP is blocked at the RakLib layer (`Network::blockAddress`) and this attempt is kicked. Kills login/reconnect floods and simple bots. |
| **Surge → lockdown** | If too many *distinct* new IPs connect within a few seconds (a distributed bot swarm, each IP under the per-IP limit), it enters a short **lockdown**: only trusted IPs (allowlist, or IPs that already logged in) are admitted; the rest are turned away. |
| **Packet guard** *(optional, off by default)* | Disconnects + blocks a logged-in session that spams packets above a pps cap. |
| **`/bshield`** | `status`, `lockdown [off|secs]`, `unblock <ip>`. Permission `bedrockshield.command` (op). |

## What it canNOT do — be honest

It runs **on the same host as the server**, in PHP, above the kernel. So it does **not** stop a volumetric/bandwidth flood, and it only sees IPs that already completed a RakNet handshake + sent a login (a raw malformed-UDP flood is handled/absorbed below it). On managed hosting, the **provider's edge** is your volumetric protection — this plugin handles the bot/login-abuse layer that gets through. For full host-side pps mitigation on a server you *do* control (root), use the iptables layer in the parent repo instead.

## Install

1. Drop **`BedrockShield.phar`** into your server's `plugins/` folder (upload via your host's panel).
2. Restart the server.
3. Edit `plugin_data/BedrockShield/config.yml` (or `plugins/BedrockShield/config.yml`), then restart again.

Requires PocketMine-MP **API 5.0.0** (tested on Essential 5.46.2).

## Build it yourself

```bash
php -d phar.readonly=0 build-phar.php   # produces BedrockShield.phar
```

## Tuning

Defaults assume a small/medium server. If legit players get turned away during a join rush, raise `login-max-attempts` / `surge-max-new-ips`. If bots still get in, lower them. `/bshield status` shows live tracking and lockdown state.

---

## 🇧🇷 Português

A metade de **camada de aplicação** do `bedrock-ddos-shield`, pra quando você **não controla o SO** (host gerenciada — só a pasta de plugins, sem SSH/root). Não mexe em `iptables`; defende onde dá: no RakLib e no fluxo de login.

### O que faz

| Mecanismo | Efeito |
|---|---|
| **Rate-limit de login por IP** | Mais de N tentativas de pré-login de um IP numa janela → o IP é bloqueado no RakLib (`Network::blockAddress`) e a tentativa é expulsa. Mata flood de login/reconexão e bots simples. |
| **Surge → lockdown** | Se muitos IPs *distintos* novos conectam em poucos segundos (enxame de bots distribuído, cada IP abaixo do limite individual), entra em **lockdown** curto: só IPs confiáveis (allowlist, ou que já logaram) entram; o resto é barrado. |
| **Guard de pacotes** *(opcional, desligado)* | Desconecta + bloqueia sessão logada que spamma pacotes acima de um teto de pps. |
| **`/bshield`** | `status`, `lockdown [off|segs]`, `unblock <ip>`. Permissão `bedrockshield.command` (op). |

### O que NÃO faz — sendo honesto

Roda **na mesma máquina do servidor**, em PHP, acima do kernel. Então **não** segura flood volumétrico/de banda, e só enxerga IPs que já completaram o handshake RakNet + login (um flood de UDP malformado é tratado abaixo dele). Em host gerenciada, a **borda do provedor** é a tua proteção volumétrica — este plugin cuida da camada de bot/abuso de login que passa. Pra mitigação pps completa num servidor que você controla (root), use a camada iptables do repo pai.

### Instalar

1. Jogue o **`BedrockShield.phar`** na pasta `plugins/` (upload pelo painel da host).
2. Reinicie o servidor.
3. Edite o `config.yml` gerado e reinicie de novo.

Requer PocketMine-MP **API 5.0.0** (testado no Essential 5.46.2).

### Buildar você mesmo

```bash
php -d phar.readonly=0 build-phar.php
```
