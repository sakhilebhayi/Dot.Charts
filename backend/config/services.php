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

    // Dot.Charts Python analytics microservice (backtesting engine)
    'analytics' => [
        'url' => env('ANALYTICS_SERVICE_URL', 'http://localhost:8001'),
    ],

    // Dot Ecosystem Knowledge Pack signing (Subsystem I2a) -- real
    // Ed25519 keypair via ext-sodium, generated once with
    // `php artisan dkp:generate-key`. The secret key file is gitignored;
    // only its derived public key is committed, inside platform.dkp.json.
    'dkp' => [
        'key_path' => env('DKP_KEY_PATH', storage_path('app/private/dkp-ed25519.key')),
    ],

    // Dot.Brain's DKP Ingestion Gateway (brain.api.md: POST /v1/dkp). No
    // real endpoint exists anywhere in the ecosystem as of 2026-08-10 --
    // Dot.Brain (~/Dot/Dot.Brain) is entirely design/architecture
    // documentation, not a deployed service (see wiki.md §5). This stays
    // null until one is deployed and configured here; DkpBrainClient
    // refuses to attempt a call while it's null rather than pointing at
    // nothing (see DkpBrainClient::publish()).
    'brain' => [
        'dkp_endpoint' => env('BRAIN_DKP_ENDPOINT'),
    ],

];
