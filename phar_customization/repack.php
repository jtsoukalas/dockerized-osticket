<?php
// Ensure the output file name is exactly what osTicket expects
$pharFile = 'auth-ldap.phar';

// Clean up old file if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile);
    
    // Start buffering to improve performance
    $phar->startBuffering();

    // 1. Build from your edited directory
    // Ensure the path 'auth_ldap' matches your folder name
    $phar->buildFromDirectory('./auth_ldap');

    // 2. Set the Stub (Crucial for osTicket to load the manifest)
    $phar->setStub($phar->createDefaultStub('manifest.php'));

    $phar->stopBuffering();
    
    echo "Successfully created: $pharFile\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}