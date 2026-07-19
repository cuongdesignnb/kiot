<?php

return [
    'pc_website' => [
        'enabled' => env('PC_INTEGRATION_ENABLED', false),
        'client_id' => env('PC_INTEGRATION_CLIENT_ID'),
        'secret' => env('PC_INTEGRATION_SECRET'),
        'default_branch_id' => env('PC_INTEGRATION_BRANCH_ID'),
        'sales_channel' => env('PC_INTEGRATION_SALES_CHANNEL', 'Website PC'),
        'timestamp_tolerance_seconds' => (int) env('PC_INTEGRATION_TIMESTAMP_TOLERANCE', 300),
        'nonce_ttl_seconds' => (int) env('PC_INTEGRATION_NONCE_TTL', 600),
        'rate_limit_per_minute' => (int) env('PC_INTEGRATION_RATE_LIMIT', 60),
        'reservation_ttl_minutes' => (int) env('PC_INTEGRATION_RESERVATION_TTL', 1440),
    ],
];
