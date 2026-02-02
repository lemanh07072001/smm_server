<?php

// Clear config cache
@unlink(__DIR__ . '/bootstrap/cache/config.php');

echo "Config cache cleared!\n";
echo "Current FRONTEND_URL: " . (getenv('FRONTEND_URL') ?: 'not set') . "\n";
