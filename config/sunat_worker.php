<?php

return [
    'enabled' => filter_var(env('SUNAT_WORKER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'queue' => env('SUNAT_WORKER_QUEUE', 'sunat'),
    'batch_size' => (int) env('SUNAT_WORKER_BATCH_SIZE', 10),
    'lease_seconds' => (int) env('SUNAT_WORKER_LEASE_SECONDS', 300),
    'stale_processing_seconds' => (int) env('SUNAT_WORKER_STALE_PROCESSING_SECONDS', 300),
    'max_attempts' => (int) env('SUNAT_WORKER_MAX_ATTEMPTS', 10),
    'backoff_seconds' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('SUNAT_WORKER_BACKOFF_SECONDS', '60,180,600,1800,3600,10800'))
    ), fn (int $seconds) => $seconds > 0)),
];
