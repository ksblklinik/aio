<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    */
    'default' => env('LOG_CHANNEL', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    */
    'channels' => [
        'daily' => [
            'driver' => 'daily',
            'path' => dirname(__DIR__) . '/storage/logs',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],

        'single' => [
            'driver' => 'single',
            'path' => dirname(__DIR__) . '/storage/logs/aio.log',
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
];
