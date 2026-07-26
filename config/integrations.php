<?php

return [
    'pc_website' => [
        'management_ui_enabled' => env('PC_INTEGRATION_MANAGEMENT_UI_ENABLED', false),
        'enabled' => env('PC_INTEGRATION_ENABLED', false),
        'client_id' => env('PC_INTEGRATION_CLIENT_ID'),
        'secret' => env('PC_INTEGRATION_SECRET'),
        'default_branch_id' => env('PC_INTEGRATION_BRANCH_ID'),
        'product_price_book_id' => env('PC_INTEGRATION_PRODUCT_PRICE_BOOK_ID'),
        'sales_channel' => env('PC_INTEGRATION_SALES_CHANNEL', 'Website PC'),
        'timestamp_tolerance_seconds' => (int) env('PC_INTEGRATION_TIMESTAMP_TOLERANCE', 300),
        'nonce_ttl_seconds' => (int) env('PC_INTEGRATION_NONCE_TTL', 600),
        'rate_limit_per_minute' => (int) env('PC_INTEGRATION_RATE_LIMIT', 60),
        'reservation_ttl_minutes' => (int) env('PC_INTEGRATION_RESERVATION_TTL', 1440),
        'pairing_ttl_seconds' => (int) env('PC_INTEGRATION_PAIRING_TTL', 600),
        'pairing_max_attempts' => (int) env('PC_INTEGRATION_PAIRING_MAX_ATTEMPTS', 5),
        'secret_rotation_grace_seconds' => (int) env('PC_INTEGRATION_SECRET_ROTATION_GRACE', 900),
        'product_images' => [
            'disk' => env('PC_PRODUCT_IMAGE_DISK', 'public'),
            'max_count' => (int) env('PC_PRODUCT_IMAGE_MAX_COUNT', 12),
            'max_size_kb' => (int) env('PC_PRODUCT_IMAGE_MAX_SIZE_KB', 5120),
            'max_pixels' => (int) env('PC_PRODUCT_IMAGE_MAX_PIXELS', 40000000),
            'webp_quality' => (int) env('PC_PRODUCT_IMAGE_WEBP_QUALITY', 82),
        ],
    ],
];
