<?php
$options = getopt('', ['input-dir:', 'output:']);
$inputDir = $options['input-dir'] ?? __DIR__ . '/example';
$pharFile = $options['output'] ?? __DIR__ . '/example.phar';

if (!is_dir($inputDir)) {
    fwrite(STDERR, "Input directory not found: {$inputDir}\n");
    exit(1);
}

// Ensure the output file name is exactly what osTicket expects
if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile);
    
    // Start buffering to improve performance
    $phar->startBuffering();

    // Build from the edited directory.
    $phar->buildFromDirectory($inputDir);

    // 2. Set the Stub (Crucial for osTicket to load the manifest)
    $phar->setStub($phar->createDefaultStub('manifest.php'));

    $phar->stopBuffering();
    
    echo "Successfully created: $pharFile\n";
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}