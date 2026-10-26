<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name & Environment
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'aio SaaS'),
    'env' => env('APP_ENV', 'local'),
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => env('APP_URL', 'http://localhost:8000'),
    'key' => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
];
