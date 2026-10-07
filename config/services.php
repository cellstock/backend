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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'back_market' => [
        'driver' => env('BACK_MARKET_DRIVER', 'mock'),

        'user_agent' => env(
            'BACK_MARKET_USER_AGENT',
            'BM-CelleXa-Marketplace;support@cellexa.com'
        ),

        'timeout' => (int) env('BACK_MARKET_TIMEOUT', 30),

        'retry_times' => (int) env('BACK_MARKET_RETRY_TIMES', 3),

        'retry_delay' => (int) env('BACK_MARKET_RETRY_DELAY', 500),

        'urls' => [
            'production' => [
                'eu' => 'https://www.backmarket.fr',
                'na' => 'https://www.backmarket.com',
                'ap' => 'https://www.backmarket.co.jp',
            ],

            'preproduction' => [
                'eu' => 'https://preprod.backmarket.fr',
                'na' => 'https://preprod.backmarket.com',
                'ap' => 'https://preprod.backmarket.co.jp',
            ],
        ],
    ],

    'refurbed' => [
        'driver' => env('REFURBED_DRIVER', 'mock'),

        'integrations_email' => env(
            'REFURBED_INTEGRATIONS_EMAIL',
            'integrations@refurbed.com'
        ),

        'webhook_base_url' => env(
            'REFURBED_WEBHOOK_BASE_URL',
            env('APP_URL')
        ),

        'catalog_max_upload_kb' => (int) env(
            'REFURBED_CATALOG_MAX_UPLOAD_KB',
            512000
        ),

        'base_url' => env(
            'REFURBED_BASE_URL',
            'https://api.refurbed.com'
        ),

        'ca_bundle' => env(
            'REFURBED_CA_BUNDLE',
            storage_path('certs/cacert.pem')
        ),

        'timeout' => (int) env(
            'REFURBED_TIMEOUT',
            30
        ),

        'retry_times' => (int) env(
            'REFURBED_RETRY_TIMES',
            3
        ),

        'retry_delay' => (int) env(
            'REFURBED_RETRY_DELAY',
            500
        ),

        'max_retry_delay' => (int) env(
            'REFURBED_MAX_RETRY_DELAY',
            60000
        ),
    ],

];
