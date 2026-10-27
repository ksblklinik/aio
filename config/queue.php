<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    */
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'failed_table' => 'failed_jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ],
];
