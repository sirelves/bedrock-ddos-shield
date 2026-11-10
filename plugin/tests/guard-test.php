<?php
/**
 * Deterministic unit tests for the BedrockShield Guard logic (no PocketMine needed).
 * Run:  php tests/guard-test.php      (exit code 0 = all passed)
 */
declare(strict_types=1);

require __DIR__ . "/../BedrockShield/src/sirelves/bedrockshield/Guard.php";

use sirelves\bedrockshield\Guard;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond) : void{
	global $pass, $fail;
	if($cond){
		$pass++;
		echo "  \033[32mPASS\033[0m $name\n";
	}else{
		$fail++;
		echo "  \033[31mFAIL\033[0m $name\n";
	}
}
function newGuard(array $o = []) : Guard{
	return new Guard(
		loginWindow: $o["loginWindow"] ?? 10,
		loginMax: $o["loginMax"] ?? 6,
		blockSeconds: $o["blockSeconds"] ?? 600,
		surgeWindow: $o["surgeWindow"] ?? 5,
		surgeMaxNewIps: $o["surgeMaxNewIps"] ?? 40,
		lockdownSeconds: $o["lockdownSeconds"] ?? 120,
		trustTtl: $o["trustTtl"] ?? 3600,
		pktMaxPps: $o["pktMaxPps"] ?? 5,
	);
}

echo "1) per-IP login rate limit\n";
$g = newGuard();
$t = 1000.0;
$lastAllowed = true;
for($i = 1; $i <= 6; $i++){
	$d = $g->onConnect("1.1.1.1", $t + $i * 0.1);
	$lastAllowed = $lastAllowed && $d["action"] === Guard::ACTION_ALLOW;
}
check("first 6 attempts allowed", $lastAllowed);
$d = $g->onConnect("1.1.1.1", $t + 0.7);
check("7th attempt -> FLOOD", $d["action"] === Guard::ACTION_FLOOD);
check("FLOOD blocks the IP (block>0)", $d["block"] > 0);

echo "2) window expiry resets the counter\n";
$g = newGuard(["loginWindow" => 10]);
for($i = 0; $i < 6; $i++){
	$g->onConnect("2.2.2.2", 1000.0 + $i);
}
$d = $g->onConnect("2.2.2.2", 1000.0 + 100); // long after the window
check("attempt after window -> ALLOW", $d["action"] === Guard::ACTION_ALLOW);

echo "3) allowlist is never blocked\n";
$g = newGuard();
$g->setAllowlist(["9.9.9.9"]);
$ok = true;
for($i = 0; $i < 100; $i++){
	$ok = $ok && $g->onConnect("9.9.9.9", 1000.0 + $i * 0.01)["action"] === Guard::ACTION_ALLOW;
}
check("100 rapid attempts from allowlisted IP all ALLOW", $ok);

echo "4) surge of distinct IPs -> lockdown\n";
$g = newGuard(["surgeMaxNewIps" => 40, "surgeWindow" => 5, "lockdownSeconds" => 120]);
$base = 2000.0;
for($i = 0; $i <= 40; $i++){ // 41 distinct IPs within <1s
	$g->onConnect("10.0." . intdiv($i, 256) . "." . ($i % 256), $base + $i * 0.01);
}
$nowL = $base + 0.5;
check("lockdown armed after 41 new IPs", $g->inLockdown($nowL));
check("justLockedDown signalled once", $g->consumeJustLockedDown());
check("...and only once", !$g->consumeJustLockedDown());

echo "5) during lockdown: untrusted kicked but NOT blocked (anti self-lockout)\n";
$d = $g->onConnect("77.77.77.77", $nowL);
check("untrusted in lockdown -> LOCKDOWN", $d["action"] === Guard::ACTION_LOCKDOWN);
check("...with block == 0 (no RakLib block)", $d["block"] === 0);

echo "6) trusted IP passes during lockdown; flooder still blocked\n";
$g->markTrusted("5.5.5.5", $nowL);
check("trusted IP -> ALLOW during lockdown", $g->onConnect("5.5.5.5", $nowL)["action"] === Guard::ACTION_ALLOW);
$fl = ["action" => Guard::ACTION_ALLOW];
for($i = 0; $i < 7; $i++){
	$fl = $g->onConnect("6.6.6.6", $nowL + $i * 0.01);
}
check("per-IP flooder blocked even in lockdown", $fl["action"] === Guard::ACTION_FLOOD && $fl["block"] > 0);

echo "7) trust EXPIRES (fail-open fix)\n";
$g = newGuard(["trustTtl" => 2]);
$g->markTrusted("8.8.8.8", 3000.0);
check("trusted right after marking", $g->isTrusted("8.8.8.8", 3001.0));
check("NOT trusted after TTL", !$g->isTrusted("8.8.8.8", 3003.0));

echo "8) blocking an IP revokes its trust\n";
$g = newGuard(["trustTtl" => 3600]);
$g->markTrusted("4.4.4.4", 4000.0);
$g->forget("4.4.4.4");
check("trust gone after forget()", !$g->isTrusted("4.4.4.4", 4001.0));

echo "9) packet guard\n";
$g = newGuard(["pktMaxPps" => 5]);
$blocked = false;
for($i = 1; $i <= 6; $i++){
	$blocked = $g->onPacket("3.3.3.3", 5000.0);
}
check("6th packet over cap -> block", $blocked === true);
check("allowlisted IP never packet-blocked", (function() : bool{
	$g = newGuard(["pktMaxPps" => 1]);
	$g->setAllowlist(["1.2.3.4"]);
	$any = false;
	for($i = 0; $i < 50; $i++){
		$any = $any || $g->onPacket("1.2.3.4", 5000.0);
	}
	return !$any;
})());

echo "10) prune expires trust and clears windows\n";
$g = newGuard(["trustTtl" => 2, "loginWindow" => 10]);
$g->markTrusted("7.0.0.1", 6000.0);
$g->onConnect("7.0.0.2", 6000.0);
$g->prune(6005.0); // 5s later: trust (ttl 2) expired, login hit (window 10) still there
check("expired trust pruned", $g->trustedCount() === 0);
$g->prune(6020.0); // 20s later: login window gone too
check("stale login windows pruned", $g->trackedCount() === 0);

echo "\n";
echo ($fail === 0 ? "\033[32m" : "\033[31m") . "$pass passed, $fail failed\033[0m\n";
exit($fail === 0 ? 0 : 1);
