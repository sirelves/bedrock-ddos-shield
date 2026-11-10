<?php

declare(strict_types=1);

namespace sirelves\bedrockshield;

/**
 * Pure decision logic for BedrockShield — NO PocketMine dependencies, so it can
 * be unit-tested deterministically with a controlled clock. Main.php wires the
 * server's events to this class and performs the actual block/kick it returns.
 *
 * onConnect() returns one of the ACTION_* constants plus how long to block the
 * IP at the RakLib layer (block=0 means "kick this attempt but DON'T block"):
 *   ACTION_ALLOW    — let the connection proceed
 *   ACTION_FLOOD    — reject + block: this IP exceeded the per-IP login rate
 *   ACTION_LOCKDOWN — reject WITHOUT blocking: we're in lockdown and this IP is
 *                     not trusted (so a legit player caught mid-attack isn't
 *                     RakLib-blocked — only actual per-IP flooders are blocked)
 */
final class Guard{

	public const ACTION_ALLOW = 0;
	public const ACTION_LOCKDOWN = 1;
	public const ACTION_FLOOD = 2;

	/** @var array<string, list<float>> ip => recent pre-login timestamps */
	private array $loginHits = [];
	/** @var array<string, float> ip => first-seen ts (surge window) */
	private array $surgeIps = [];
	/** @var array<string, float> ip => trust EXPIRY ts (0/absent = not trusted) */
	private array $knownIps = [];
	/** @var array<string, int> ip => packets this second */
	private array $pktCount = [];
	/** @var array<string, bool> */
	private array $allowlist = [];

	private float $lockdownUntil = 0.0;
	private bool $justLockedDown = false;

	public function __construct(
		private int $loginWindow,
		private int $loginMax,
		private int $blockSeconds,
		private int $surgeWindow,
		private int $surgeMaxNewIps,
		private int $lockdownSeconds,
		private int $trustTtl = 3600,
		private int $pktMaxPps = 500,
	){
	}

	/** @param list<string> $ips */
	public function setAllowlist(array $ips) : void{
		$this->allowlist = [];
		foreach($ips as $ip){
			$this->allowlist[$ip] = true;
		}
	}

	public function isAllowlisted(string $ip) : bool{
		return isset($this->allowlist[$ip]);
	}

	public function isTrusted(string $ip, float $now) : bool{
		return isset($this->allowlist[$ip]) || (($this->knownIps[$ip] ?? 0.0) > $now);
	}

	/** Grant/refresh trust for an IP that actually joined. Trust EXPIRES after trustTtl. */
	public function markTrusted(string $ip, float $now) : void{
		$this->knownIps[$ip] = $now + $this->trustTtl;
	}

	public function inLockdown(float $now) : bool{
		return $now < $this->lockdownUntil;
	}

	public function lockdownRemaining(float $now) : int{
		return $this->inLockdown($now) ? (int) ceil($this->lockdownUntil - $now) : 0;
	}

	/** True exactly once after a connection triggered a new lockdown (for logging). */
	public function consumeJustLockedDown() : bool{
		$v = $this->justLockedDown;
		$this->justLockedDown = false;
		return $v;
	}

	public function trackedCount() : int{
		return count($this->loginHits);
	}

	public function trustedCount() : int{
		return count($this->knownIps);
	}

	public function forceLockdown(float $now, int $seconds) : void{
		$this->lockdownUntil = $now + $seconds;
	}

	public function clearLockdown() : void{
		$this->lockdownUntil = 0.0;
	}

	/** Forget an IP's state AND revoke its trust (called when we block it). */
	public function forget(string $ip) : void{
		unset($this->loginHits[$ip], $this->pktCount[$ip], $this->knownIps[$ip]);
	}

	/**
	 * Decide what to do with an incoming pre-login from $ip at time $now.
	 * @return array{action:int, block:int, reason:string}
	 */
	public function onConnect(string $ip, float $now) : array{
		if(isset($this->allowlist[$ip])){
			return ["action" => self::ACTION_ALLOW, "block" => 0, "reason" => "allowlist"];
		}

		// 1) per-IP pre-login rate limit — runs FIRST so a real flooder is blocked
		//    even during lockdown (and so lockdown itself never blocks, see step 2).
		$cut = $now - $this->loginWindow;
		$hits = $this->loginHits[$ip] ?? [];
		$hits = array_values(array_filter($hits, static fn(float $t) : bool => $t >= $cut));
		$hits[] = $now;
		$this->loginHits[$ip] = $hits;
		if(count($hits) > $this->loginMax){
			$this->forget($ip); // revoke any trust: this IP is now hostile
			return ["action" => self::ACTION_FLOOD, "block" => $this->blockSeconds, "reason" => "login-flood"];
		}

		// 2) lockdown — turn away untrusted IPs, but DO NOT block them (block=0).
		//    A legit player caught mid-attack just retries; only step-1 flooders
		//    actually get RakLib-blocked. This removes the self-lockout risk.
		if($now < $this->lockdownUntil && !$this->isTrusted($ip, $now)){
			return ["action" => self::ACTION_LOCKDOWN, "block" => 0, "reason" => "lockdown"];
		}

		// 3) global surge → arm lockdown (only while NOT already locked down, so an
		//    attacker can't stretch it past surge+lockdownSeconds by re-triggering).
		if(!$this->isTrusted($ip, $now) && !isset($this->surgeIps[$ip])){
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
				$this->justLockedDown = true;
			}
		}

		return ["action" => self::ACTION_ALLOW, "block" => 0, "reason" => "ok"];
	}

	/** Packet-guard: returns true if this session should be blocked+disconnected. */
	public function onPacket(string $ip, float $now) : bool{
		if(isset($this->allowlist[$ip])){
			return false;
		}
		$n = ($this->pktCount[$ip] ?? 0) + 1;
		$this->pktCount[$ip] = $n;
		if($n > $this->pktMaxPps){
			unset($this->pktCount[$ip]);
			return true;
		}
		return false;
	}

	/** Called ~once per second: expire old windows + trust, reset counters, cap memory. */
	public function prune(float $now) : void{
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
		foreach($this->knownIps as $ip => $exp){
			if($exp <= $now){
				unset($this->knownIps[$ip]);
			}
		}
		$this->pktCount = [];
		if(count($this->loginHits) > 50000){
			$this->loginHits = array_slice($this->loginHits, -20000, null, true);
		}
		if(count($this->knownIps) > 50000){
			$this->knownIps = array_slice($this->knownIps, -20000, null, true);
		}
	}
}
