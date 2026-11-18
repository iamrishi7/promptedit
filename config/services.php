<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),
        'timeout_seconds' => 15,
        'pricing_cache_seconds' => 600,
        // Pixelforge allows 60 requests/minute per account: submissions use up to 30, polling up to 20.
        'poll_requests_per_minute' => 20,
        // Pixelforge runs at most this many generations at once per account.
        'max_concurrent_generations' => 3,
        // Where our copies of generated images live: a private local disk in development, object
        // storage (e.g. "s3") when deployed. Pixelforge's own links expire after 24 hours.
        'image_disk' => env('PIXELFORGE_IMAGE_DISK', 'local'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
