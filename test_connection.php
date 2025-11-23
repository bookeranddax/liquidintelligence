<?php
// test_connection.php - Quick database connection test
// Access this file at: http://liquidintelligence.test/test_connection.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Database Connection Test</h1>";
echo "<pre>";

try {
    // Load config
    $config = require __DIR__ . '/config.php';
    $db = $config['db'];
    
    echo "Environment Detection:\n";
    echo "  Server Name: " . ($_SERVER['SERVER_NAME'] ?? 'not set') . "\n";
    echo "  HTTP Host: " . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
    echo "\n";
    
    echo "Database Configuration:\n";
    echo "  Host: " . $db['host'] . "\n";
    echo "  Database: " . $db['name'] . "\n";
    echo "  User: " . $db['user'] . "\n";
    echo "  Password: " . (empty($db['pass']) ? '(empty)' : str_repeat('*', strlen($db['pass']))) . "\n";
    echo "\n";
    
    // Try to connect
    echo "Attempting connection...\n";
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✓ Connection successful!\n\n";
    
    // Get MySQL version
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "MySQL Version: $version\n\n";
    
    // Check if our database exists and show tables
    echo "Tables in database:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        echo "  (no tables found - database may be empty)\n";
    } else {
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  - $table ($count rows)\n";
        }
    }
    
    echo "\n✓ All checks passed!\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
