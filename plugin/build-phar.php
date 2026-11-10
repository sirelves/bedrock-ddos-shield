<?php
/**
 * Build BedrockShield.phar from the source folder.
 * Run with phar writing enabled:
 *   php -d phar.readonly=0 build-phar.php
 */
declare(strict_types=1);

$src = __DIR__ . "/BedrockShield";
$out = __DIR__ . "/BedrockShield.phar";

if(!is_file($src . "/plugin.yml")){
	fwrite(STDERR, "plugin.yml not found in $src\n");
	exit(1);
}
@unlink($out);

$phar = new Phar($out);
$phar->setStub('<?php echo "BedrockShield — PocketMine-MP plugin. See https://github.com/sirelves/bedrock-ddos-shield\n"; __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->startBuffering();
$phar->buildFromDirectory($src);
$phar->stopBuffering();

printf("Built %s (%d files, %d bytes)\n", basename($out), count(iterator_to_array($phar)), filesize($out));
