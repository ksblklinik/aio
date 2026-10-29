<?php

declare(strict_types=1);

$rawDbPath = env('DB_PATH', 'database.sqlite');
$dbPath = ($rawDbPath === ':memory:' || str_starts_with($rawDbPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rawDbPath))
    ? $rawDbPath
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($rawDbPath, '/\\');

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    */
    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'path' => $dbPath,
            'database' => $dbPath,
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Convenience Accessors
    |--------------------------------------------------------------------------
    */
    'path' => $dbPath,
];
