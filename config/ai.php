<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Driver
    |--------------------------------------------------------------------------
    */
    'default' => env('AI_DRIVER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | AI Service Providers Configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'mock' => [
            'driver' => 'mock',
            'model' => 'mock-gpt-4o',
        ],

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('AI_TIMEOUT', 30),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY', ''),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'timeout' => (int) env('AI_TIMEOUT', 30),
        ],
    ],
];
