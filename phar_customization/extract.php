<?php
$baseDir = dirname(__DIR__);
$pharPath = $baseDir . '/plugins/auth-ldap.phar';
$extractDir = __DIR__ . '/auth_ldap';

if (!is_file($pharPath)) {
	fwrite(STDERR, "PHAR file not found: {$pharPath}\n");
	exit(1);
}

try {
	$phar = new Phar($pharPath);
	$phar->extractTo($extractDir, null, true);
	echo "Extraction complete: {$extractDir}\n";
} catch (Exception $e) {
	fwrite(STDERR, "Extraction failed: " . $e->getMessage() . "\n");
	exit(1);
}
?>