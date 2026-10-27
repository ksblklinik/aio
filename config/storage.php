<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */
    'default' => env('STORAGE_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => dirname(__DIR__) . '/storage/app',
        ],

        'public' => [
            'driver' => 'local',
            'root' => dirname(__DIR__) . '/storage/app/public',
            'url' => env('APP_URL', 'http://localhost:8000') . '/storage',
            'visibility' => 'public',
        ],
    ],
];
