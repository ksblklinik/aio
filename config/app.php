<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name & Environment
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'aio SaaS'),
    'tagline' => env('APP_TAGLINE', 'Unified Platform'),
    'env' => env('APP_ENV', 'local'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8000'),
    'key' => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'logo' => env('APP_LOGO', null),
    'favicon' => env('APP_FAVICON', '/favicon.ico'),
];
