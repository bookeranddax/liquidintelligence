<?php

// /config.php - Environment-aware database configuration
// This file automatically detects whether it's running locally (Laragon) or on production (Dreamhost)

// Detect environment based on server name or hostname
$isLocal = (
    $_SERVER['SERVER_NAME'] === 'localhost' ||
    $_SERVER['SERVER_NAME'] === 'liquidintelligence.test' ||
    strpos($_SERVER['SERVER_NAME'], '.local') !== false ||
    strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'] ?? '', '.test') !== false
);

if ($isLocal) {
    // LOCAL DEVELOPMENT (Laragon) SETTINGS
    $dbConfig = [
        'host'    => '127.0.0.1',           // Laragon MySQL host
        'name'    => 'liquidintelligencedb',
        'user'    => 'root',                 // Default Laragon user
        'pass'    => '',                     // Default Laragon password (empty)
        'charset' => 'utf8mb4',
    ];
} else {
    // PRODUCTION (Dreamhost) SETTINGS
    $dbConfig = [
        'host'    => 'mysql.cookingissues.com',  // Your Dreamhost MySQL hostname
        'name'    => 'liquidintelligencedb',
        'user'    => 'developer',                 // Replace with your actual Dreamhost username
        'pass'    => 'devpass',                   // Replace with your actual Dreamhost password
        'charset' => 'utf8mb4',
    ];
}

// Add the solver configuration weights and bands (used by calculator)
return [
    'db' => $dbConfig,
    
    // Weights for least-squares optimization (calculator feature)
    'weights' => [
        'brix'    => 1.0 / (0.15 * 0.15),    // ~0.15 °Bx sigma
        'density' => 1.0 / (0.0006 * 0.0006) // ~0.0006 g/mL sigma
    ],
    
    // Range bands for candidate search (calculator feature)
    'bands' => [
        'brix'    => ['initial' => 0.25,  'expand' => 1.8, 'max' => 2.0],
        'density' => ['initial' => 0.0008,'expand' => 1.8, 'max' => 0.006]
    ],
    
    // Solver tolerance setting
    'tolerance' => 1e-3, // squared-error tolerance to accept a solution as "in-range"
];
