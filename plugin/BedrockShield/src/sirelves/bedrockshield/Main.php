<?php

declare(strict_types=1);

namespace sirelves\bedrockshield;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;

/**
 * BedrockShield — application-layer flood/bot mitigation for managed/no-root hosts.
 * All decisions live in Guard (pure, unit-tested); this class only wires PocketMine
 * events to it and performs the block/kick Guard asks for.
 */
final class Main extends PluginBase implements Listener{

	private Guard $guard;
	private bool $pktGuard;

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		$c = $this->getConfig();

		$this->guard = new Guard(
			loginWindow: max(1, (int) $c->get("login-window-seconds", 10)),
			loginMax: max(1, (int) $c->get("login-max-attempts", 6)),
			blockSeconds: max(1, (int) $c->get("block-seconds", 600)),
			surgeWindow: max(1, (int) $c->get("surge-window-seconds", 5)),
			surgeMaxNewIps: max(1, (int) $c->get("surge-max-new-ips", 40)),
			lockdownSeconds: max(1, (int) $c->get("lockdown-seconds", 120)),
			trustTtl: max(60, (int) $c->get("trust-ttl-seconds", 3600)),
			pktMaxPps: max(50, (int) $c->get("packet-guard-max-pps", 500)),
		);
		$this->guard->setAllowlist(array_map(strval(...), (array) $c->get("allowlist", [])));
		$this->pktGuard = (bool) $c->get("packet-guard-enabled", false);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->guard->prune(microtime(true));
		}), 20); // ~1s

		$this->getLogger()->info(TF::AQUA . "BedrockShield on");
	}

	public function onPreLogin(PlayerPreLoginEvent $event) : void{
		$ip = $event->getIp();
		$d = $this->guard->onConnect($ip, microtime(true));

		if($this->guard->consumeJustLockedDown()){
			$this->getLogger()->warning("Surge detected -> lockdown for {$this->guard->lockdownRemaining(microtime(true))}s (only trusted IPs admitted)");
		}

		switch($d["action"]){
			case Guard::ACTION_FLOOD:
				$event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "Too many connection attempts.");
				break;
			case Guard::ACTION_LOCKDOWN:
				$event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "Server under attack - try again shortly.");
				break;
			default:
				return; // ACTION_ALLOW
		}
		if($d["block"] > 0){
			$this->getServer()->getNetwork()->blockAddress($ip, $d["block"]);
			$this->getLogger()->notice("blocked {$ip} for {$d['block']}s ({$d['reason']})");
		}
	}

	/** Trust is granted only once a player has really JOINED, and it expires (see Guard). */
	public function onJoin(PlayerJoinEvent $event) : void{
		$this->guard->markTrusted($event->getPlayer()->getNetworkSession()->getIp(), microtime(true));
	}

	public function onDataPacket(DataPacketReceiveEvent $event) : void{
		if(!$this->pktGuard){
			return;
		}
		$session = $event->getOrigin();
		$ip = $session->getIp();
		if($this->guard->onPacket($ip, microtime(true))){
			$this->getServer()->getNetwork()->blockAddress($ip, (int) $this->getConfig()->get("block-seconds", 600));
			$session->disconnect("Packet flood");
			$this->guard->forget($ip);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($command->getName() !== "bshield"){
			return false;
		}
		$now = microtime(true);
		switch(strtolower($args[0] ?? "status")){
			case "status":
				$lock = $this->guard->inLockdown($now) ? "ON (" . $this->guard->lockdownRemaining($now) . "s left)" : "off";
				$sender->sendMessage(TF::AQUA . "BedrockShield " . TF::WHITE .
					"| tracked IPs: " . $this->guard->trackedCount() .
					" | trusted: " . $this->guard->trustedCount() .
					" | lockdown: " . $lock);
				return true;

			case "lockdown":
				$arg = strtolower($args[1] ?? "");
				if($arg === "off"){
					$this->guard->clearLockdown();
					$sender->sendMessage(TF::GREEN . "Lockdown disabled.");
				}else{
					$secs = is_numeric($arg) ? (int) $arg : (int) $this->getConfig()->get("lockdown-seconds", 120);
					$this->guard->forceLockdown($now, $secs);
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
				$this->guard->forget($ip);
				$sender->sendMessage(TF::GREEN . "Unblocked {$ip}.");
				return true;

			default:
				$sender->sendMessage(TF::AQUA . "/bshield <status|lockdown [off|secs]|unblock <ip>>");
				return true;
		}
	}
}
