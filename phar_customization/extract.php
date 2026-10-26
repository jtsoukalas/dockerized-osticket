<?php
$baseDir = dirname(__DIR__);
$options = getopt('', ['phar:', 'output-dir:']);
$pharPath = $options['phar'] ?? $baseDir . '/plugins/example.phar';
$extractDir = $options['output-dir'] ?? __DIR__ . '/example';

if (!is_file($pharPath)) {
	fwrite(STDERR, "PHAR file not found: {$pharPath}\n");
	exit(1);
}

try {
	if (!is_dir($extractDir) && !mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
		throw new RuntimeException("Unable to create extraction directory: {$extractDir}");
	}

	$phar = new Phar($pharPath);
	$phar->extractTo($extractDir, null, true);
	echo "Extraction complete: {$extractDir}\n";
} catch (Exception $e) {
	fwrite(STDERR, "Extraction failed: " . $e->getMessage() . "\n");
	exit(1);
}
?>