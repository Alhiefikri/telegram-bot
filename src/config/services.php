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

    'pakasir' => [
        'slug' => env('PAKASIR_SLUG'),
        'api_key' => env('PAKASIR_API_KEY'),
        'base_url' => env('PAKASIR_BASE_URL', 'https://app.pakasir.com'),
        'http_timeout_seconds' => (float) env('PAKASIR_HTTP_TIMEOUT_SECONDS', 10),
        'http_connect_timeout_seconds' => (float) env('PAKASIR_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        'transaction_detail_max_attempts' => (int) env('PAKASIR_TRANSACTION_DETAIL_MAX_ATTEMPTS', 3),
        'transaction_detail_retry_delay_ms' => (int) env('PAKASIR_TRANSACTION_DETAIL_RETRY_DELAY_MS', 250),
        'transaction_detail_retry_max_delay_ms' => (int) env('PAKASIR_TRANSACTION_DETAIL_RETRY_MAX_DELAY_MS', 1000),
        'reconcile_released_lookback_hours' => (int) env('PAKASIR_RECONCILE_RELEASED_LOOKBACK_HOURS', 72),
        'redirect_url' => env('PAKASIR_REDIRECT_URL'),
        'webhook_rate_limit_per_minute' => env('PAKASIR_WEBHOOK_RATE_LIMIT_PER_MINUTE', 30),
        'status_refresh_rate_limit_per_minute' => env('PAKASIR_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE', 3),
    ],

    'storefront' => [
        'checkout_rate_limit_per_minute' => (int) env('STOREFRONT_CHECKOUT_RATE_LIMIT_PER_MINUTE', 5),
        'invoice_url_ttl_days' => (int) env('STOREFRONT_INVOICE_URL_TTL_DAYS', 7),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'http_timeout_seconds' => (float) env('TELEGRAM_HTTP_TIMEOUT_SECONDS', 10),
        'http_connect_timeout_seconds' => (float) env('TELEGRAM_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        'payment_mode' => 'manual',
        'admin_contact_url' => env('TELEGRAM_ADMIN_CONTACT_URL'),
        'admin_username' => env('TELEGRAM_ADMIN_USERNAME'),
        'admin_user_id' => env('TELEGRAM_ADMIN_USER_ID'),
        'admin_telegram_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
        'welcome_banner_url' => env('TELEGRAM_WELCOME_BANNER_URL'),
        'webhook_rate_limit_per_minute' => env('TELEGRAM_WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'dogfood_timing' => [
        'allow_runtime' => env('APP_ALLOW_DOGFOOD_RUNTIME', false),
        'enabled' => env('DOGFOOD_TIMING_ENABLED', false),
        'log_channel' => env('DOGFOOD_TIMING_LOG_CHANNEL'),
        'fake_telegram_outbound' => env('DOGFOOD_FAKE_TELEGRAM_OUTBOUND', false),
        'fake_telegram_latency_us' => env('DOGFOOD_FAKE_TELEGRAM_LATENCY_US', 1000),
    ],

    'dogfood_fixtures' => [
        'admin_email' => env('DOGFOOD_ADMIN_EMAIL', env('PLAYWRIGHT_ADMIN_EMAIL')),
        'admin_password' => env('DOGFOOD_ADMIN_PASSWORD', env('PLAYWRIGHT_ADMIN_PASSWORD')),
        'telegram_photo_file_id' => env('DOGFOOD_TELEGRAM_PHOTO_FILE_ID'),
        'store_channel_chat_id' => env('DOGFOOD_STORE_CHANNEL_CHAT_ID'),
        'store_channel_username' => env('DOGFOOD_STORE_CHANNEL_USERNAME'),
        'store_channel_link' => env('DOGFOOD_STORE_CHANNEL_LINK'),
        'store_channel_member_user_id' => env('DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID'),
        'store_channel_non_member_user_id' => env('DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID'),
        'telegram_callback_query_id' => env('DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID'),
        'pakasir_pending_order_id' => env('DOGFOOD_PAKASIR_PENDING_ORDER_ID'),
        'pakasir_pending_amount' => env('DOGFOOD_PAKASIR_PENDING_AMOUNT'),
    ],

];
