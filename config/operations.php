<?php

return [
    'alert_email' => env('OPERATIONS_ALERT_EMAIL'),
    'alert_cooldown_minutes' => (int) env('OPERATIONS_ALERT_COOLDOWN_MINUTES', 60),
    'db_connections_warning_percent' => (int) env('OPERATIONS_DB_CONNECTIONS_WARNING_PERCENT', 70),
    'db_connections_critical_percent' => (int) env('OPERATIONS_DB_CONNECTIONS_CRITICAL_PERCENT', 85),
    'staging_review_at' => env('OPERATIONS_STAGING_REVIEW_AT'),
];
