<?php

declare(strict_types=1);

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
            'path' => env('DB_PATH', dirname(__DIR__) . '/database.sqlite'),
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Convenience Accessors
    |--------------------------------------------------------------------------
    */
    'path' => env('DB_PATH', dirname(__DIR__) . '/database.sqlite'),
];
