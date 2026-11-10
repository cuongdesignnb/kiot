<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'attendance_agent' => [
        'hmac_key' => env('ATTENDANCE_AGENT_SECRET', 'your-long-random-secret-here-change-me'),
        'timestamp_tolerance' => (int) env('ATTENDANCE_AGENT_TIMESTAMP_TOLERANCE', 300),
        // The production device reports inOutMode 0 ("in") or 4 ("out") for
        // punches regardless of whether the employee arrived or left (morning
        // arrivals are stored as "out", evening exits as "in"). Device direction
        // is therefore not evidence of entry/exit: punches are paired by time
        // order and shift bounds unless a deployment whose staff really press
        // the In/Out keys opts back in.
        'trust_device_direction' => (bool) env('ATTENDANCE_TRUST_DEVICE_DIRECTION', false),
    ],

];
