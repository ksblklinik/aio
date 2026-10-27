<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    */
    'default' => env('MAIL_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */
    'mailers' => [
        'log' => [
            'transport' => 'log',
            'path' => dirname(__DIR__) . '/storage/logs/mail.log',
        ],

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', null),
            'username' => env('MAIL_USERNAME', null),
            'password' => env('MAIL_PASSWORD', null),
            'timeout' => (int) env('MAIL_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@aio.local'),
        'name' => env('MAIL_FROM_NAME', 'aio SaaS'),
    ],
];
