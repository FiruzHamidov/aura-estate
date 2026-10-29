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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'auth_ttl_seconds' => (int) env('TELEGRAM_AUTH_TTL_SECONDS', 300),
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE', 'https://api.openai.com'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
        'api_key' => env('OPENAI_API_KEY'),
        'relay_shared_key' => env('RELAY_SHARED_KEY'),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/private/firebase-service-account.json')),
        'project_id' => env('FIREBASE_PROJECT_ID', 'aura-estate-e7696'),
    ],

];
