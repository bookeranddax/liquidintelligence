<?php
header('Content-Type: text/plain');
echo "User: " . get_current_user() . "\n";
echo "Dir:  " . __DIR__ . "\n";
echo "PHP:  " . PHP_VERSION . "\n";
