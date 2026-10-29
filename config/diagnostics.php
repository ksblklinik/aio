<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Diagnostics & Profiler Configuration
    |--------------------------------------------------------------------------
    |
    | Pengaturan pemantau performa runtime dan query profiler SQLite.
    | Mendukung pelacakan query lambat persisten lintas-proses dan proteksi PII.
    |
    */

    'profiler_enabled' => (bool) env('PROFILER_ENABLED', true),

    // Ambang batas latensi query dalam milidetik untuk diklasifikasikan lambat
    'slow_query_threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 25.0),

    // Batas jumlah query lambat yang disimpan dalam persistent ring-buffer
    'slow_query_max_buffer' => (int) env('SLOW_QUERY_MAX_BUFFER', 100),

    // Lokasi berkas persistent ring-buffer (default null mengarah ke storage/diagnostics/slow_queries.json)
    'slow_query_storage_path' => env('SLOW_QUERY_STORAGE_PATH', null),

    // Batas konsumsi memori sebelum memicu peringatan (default: 64MB)
    'memory_warning_threshold_bytes' => (int) env('MEMORY_WARNING_THRESHOLD_BYTES', 67108864),
];
