<?php

declare(strict_types=1);

namespace sirelves\bedrockshield;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;

/**
 * BedrockShield — application-layer flood/bot mitigation.
 *
 * It cannot drop packets in the kernel (no root), but it CAN:
 *  - rate-limit pre-login attempts per source IP and block offenders at the
 *    RakLib layer via Network::blockAddress(), so RakLib stops processing them;
 *  - detect a surge of many new IPs and enter a short "lockdown" where only
 *    trusted IPs are admitted (defeats low-per-IP distributed bot floods);
 *  - optionally disconnect+block a logged-in session that spams packets.
 *
 * Volumetric (bandwidth) floods are NOT in scope — that is the host's edge.
 */
final class Main extends PluginBase implements Listener{

	/** @var array<string, list<float>> ip => recent pre-login timestamps */
	private array $loginHits = [];
	/** @var array<string, float> ip => first-seen ts of a new connection (surge window) */
	private array $surgeIps = [];
	/** @var array<string, bool> ip => trusted (logged in successfully, or allowlisted) */
	private array $knownIps = [];
	/** @var array<string, int> ip => packet count in the current second (packet guard) */
	private array $pktCount = [];
	/** @var array<string, bool> */
	private array $allowlist = [];

	private float $lockdownUntil = 0.0;

	private int $loginWindow;
	private int $loginMax;
	private int $blockSeconds;
	private int $surgeWindow;
	private int $surgeMaxNewIps;
	private int $lockdownSeconds;
	private bool $pktGuard;
	private int $pktMaxPps;

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		$c = $this->getConfig();
		$this->loginWindow     = max(1, (int) $c->get("login-window-seconds", 10));
		$this->loginMax        = max(1, (int) $c->get("login-max-attempts", 6));
		$this->blockSeconds    = max(1, (int) $c->get("block-seconds", 600));
		$this->surgeWindow     = max(1, (int) $c->get("surge-window-seconds", 5));
		$this->surgeMaxNewIps  = max(1, (int) $c->get("surge-max-new-ips", 40));
		$this->lockdownSeconds = max(1, (int) $c->get("lockdown-seconds", 120));
		$this->pktGuard        = (bool) $c->get("packet-guard-enabled", false);
		$this->pktMaxPps       = max(50, (int) $c->get("packet-guard-max-pps", 500));
		foreach((array) $c->get("allowlist", []) as $ip){
			$this->allowlist[(string) $ip] = true;
		}

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->prune();
		}), 20); // every ~1s

		$this->getLogger()->info(TF::AQUA . "BedrockShield on — login " . $this->loginMax . "/" . $this->loginWindow .
			"s, surge>" . $this->surgeMaxNewIps . "/" . $this->surgeWindow . "s -> lockdown " . $this->lockdownSeconds . "s");
	}

	private function now() : float{
		return microtime(true);
	}

	private function isTrusted(string $ip) : bool{
		return isset($this->allowlist[$ip]) || isset($this->knownIps[$ip]);
	}

	private function block(string $ip, int $seconds, string $why) : void{
		if(isset($this->allowlist[$ip])){
			return; // never block an allowlisted IP
		}
		$this->getServer()->getNetwork()->blockAddress($ip, $seconds);
		$this->getLogger()->notice("blocked $ip for {$seconds}s ($why)");
	}

	public function onPreLogin(PlayerPreLoginEvent $event) : void{
		$ip = $event->getIp();
		if(isset($this->allowlist[$ip])){
			return;
		}
		$now = $this->now();

		// 1) lockdown: during a surge, only trusted IPs get in
		if($now < $this->lockdownUntil && !$this->isTrusted($ip)){
			$event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "Server under attack - try again shortly.");
			$this->block($ip, 30, "lockdown");
			return;
		}

		// 2) per-IP pre-login rate limit
		$cut = $now - $this->loginWindow;
		$hits = $this->loginHits[$ip] ?? [];
		$hits = array_values(array_filter($hits, static fn(float $t) : bool => $t >= $cut));
		$hits[] = $now;
		$this->loginHits[$ip] = $hits;
		if(count($hits) > $this->loginMax){
			$event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "Too many connection attempts.");
			$this->block($ip, $this->blockSeconds, "login-flood");
			unset($this->loginHits[$ip]);
			return;
		}

		// 3) global surge detection -> lockdown
		if(!$this->isTrusted($ip) && !isset($this->surgeIps[$ip])){
			$this->surgeIps[$ip] = $now;
		}
		if($now >= $this->lockdownUntil){
			$sc = $now - $this->surgeWindow;
			$n = 0;
			foreach($this->surgeIps as $t){
				if($t >= $sc){
					$n++;
				}
			}
			if($n > $this->surgeMaxNewIps){
				$this->lockdownUntil = $now + $this->lockdownSeconds;
				$this->getLogger()->warning("SURGE: $n new IPs in {$this->surgeWindow}s -> lockdown {$this->lockdownSeconds}s");
			}
		}
	}

	public function onLogin(PlayerLoginEvent $event) : void{
		// a client that completes login is treated as a real (trusted) source IP
		$this->knownIps[$event->getPlayer()->getNetworkSession()->getIp()] = true;
	}

	public function onDataPacket(DataPacketReceiveEvent $event) : void{
		if(!$this->pktGuard){
			return;
		}
		$session = $event->getOrigin();
		$ip = $session->getIp();
		if(isset($this->allowlist[$ip])){
			return;
		}
		$n = ($this->pktCount[$ip] ?? 0) + 1;
		$this->pktCount[$ip] = $n;
		if($n > $this->pktMaxPps){
			unset($this->pktCount[$ip]);
			$this->block($ip, $this->blockSeconds, "packet-flood");
			$session->disconnect("Packet flood");
		}
	}

	private function prune() : void{
		$now = $this->now();

		$cut = $now - $this->loginWindow;
		foreach($this->loginHits as $ip => $hits){
			$hits = array_values(array_filter($hits, static fn(float $t) : bool => $t >= $cut));
			if($hits === []){
				unset($this->loginHits[$ip]);
			}else{
				$this->loginHits[$ip] = $hits;
			}
		}

		$sc = $now - $this->surgeWindow;
		foreach($this->surgeIps as $ip => $t){
			if($t < $sc){
				unset($this->surgeIps[$ip]);
			}
		}

		$this->pktCount = []; // reset packet counters each second

		// defensive memory caps
		if(count($this->loginHits) > 50000){
			$this->loginHits = array_slice($this->loginHits, -20000, null, true);
		}
		if(count($this->knownIps) > 50000){
			$this->knownIps = array_slice($this->knownIps, -20000, null, true);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($command->getName() !== "bshield"){
			return false;
		}
		switch(strtolower($args[0] ?? "status")){
			case "status":
				$lock = $this->now() < $this->lockdownUntil
					? "ON (" . (int) ceil($this->lockdownUntil - $this->now()) . "s left)"
					: "off";
				$sender->sendMessage(TF::AQUA . "BedrockShield " . TF::WHITE .
					"| tracked IPs: " . count($this->loginHits) .
					" | trusted: " . count($this->knownIps) .
					" | lockdown: " . $lock);
				return true;

			case "lockdown":
				$arg = strtolower($args[1] ?? "");
				if($arg === "off"){
					$this->lockdownUntil = 0.0;
					$sender->sendMessage(TF::GREEN . "Lockdown disabled.");
				}else{
					$secs = is_numeric($arg) ? (int) $arg : $this->lockdownSeconds;
					$this->lockdownUntil = $this->now() + $secs;
					$sender->sendMessage(TF::YELLOW . "Lockdown ON for {$secs}s.");
				}
				return true;

			case "unblock":
				$ip = (string) ($args[1] ?? "");
				if($ip === ""){
					$sender->sendMessage(TF::RED . "Usage: /bshield unblock <ip>");
					return true;
				}
				$this->getServer()->getNetwork()->unblockAddress($ip);
				unset($this->loginHits[$ip]);
				$sender->sendMessage(TF::GREEN . "Unblocked {$ip}.");
				return true;

			default:
				$sender->sendMessage(TF::AQUA . "/bshield <status|lockdown [off|secs]|unblock <ip>>");
				return true;
		}
	}
}
