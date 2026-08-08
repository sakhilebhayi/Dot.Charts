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

    /*
    |--------------------------------------------------------------------------
    | ChartSense FREE Public APIs (NO API KEYS REQUIRED)
    |--------------------------------------------------------------------------
    | These APIs work without authentication and provide comprehensive data
    */

    // Cryptocurrency Data (100% FREE)
    'coingecko' => [
        'url' => 'https://api.coingecko.com/api/v3',
        'description' => 'CoinGecko - Comprehensive crypto data (100% FREE)',
    ],

    'binance' => [
        'url' => 'https://api.binance.com/api/v3',
        'description' => 'Binance - Real-time crypto prices (100% FREE Public API)',
    ],

    'coinpaprika' => [
        'url' => 'https://api.coinpaprika.com/v1',
        'description' => 'Coinpaprika - Crypto prices & market data (100% FREE)',
    ],

    'coincap' => [
        'url' => 'https://api.coincap.io/v2',
        'description' => 'CoinCap - Real-time crypto prices (100% FREE)',
    ],

    // Currency Exchange (100% FREE)
    'exchangerate' => [
        'url' => 'https://api.exchangerate.host',
        'description' => 'ExchangeRate.host - Free forex rates (100% FREE)',
    ],

    'frankfurter' => [
        'url' => 'https://api.frankfurter.app',
        'description' => 'Frankfurter - Exchange rates & currency conversion (100% FREE)',
    ],

    // Social Sentiment (100% FREE)
    'wallstreetbets' => [
        'url' => 'https://dashboard.nbshare.io/apps/reddit/api/',
        'description' => 'WallStreetBets - Stock sentiment from Reddit (100% FREE)',
    ],

    // Dot.Charts Python analytics microservice (backtesting engine)
    'analytics' => [
        'url' => env('ANALYTICS_SERVICE_URL', 'http://localhost:8001'),
    ],

];
