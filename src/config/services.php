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

    'tripay' => [
        'api_key' => env('TRIPAY_API_KEY'),
        'private_key' => env('TRIPAY_PRIVATE_KEY'),
        'merchant_code' => env('TRIPAY_MERCHANT_CODE'),
        'base_url' => env('TRIPAY_BASE_URL', 'https://tripay.co.id/api-sandbox'),
        'callback_url' => env('TRIPAY_CALLBACK_URL'),
        'return_url' => env('TRIPAY_RETURN_URL'),
        'webhook_rate_limit_per_minute' => env('TRIPAY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 30),
        'webhook_max_body_bytes' => env('TRIPAY_WEBHOOK_MAX_BODY_BYTES', 65536),
    ],

    'pakasir' => [
        'slug' => env('PAKASIR_SLUG'),
        'api_key' => env('PAKASIR_API_KEY'),
        'base_url' => env('PAKASIR_BASE_URL', 'https://app.pakasir.com'),
        'redirect_url' => env('PAKASIR_REDIRECT_URL'),
        'qris_only' => env('PAKASIR_QRIS_ONLY', false),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'payment_mode' => env('TELEGRAM_PAYMENT_MODE', 'manual_dana'),
        'default_payment_method' => env('TELEGRAM_DEFAULT_PAYMENT_METHOD', 'BRIVA'),
        'dana_qr_image_url' => env('DANA_QR_IMAGE_URL'),
        'dana_account_name' => env('DANA_ACCOUNT_NAME'),
        'dana_account_number' => env('DANA_ACCOUNT_NUMBER'),
        'admin_contact_url' => env('TELEGRAM_ADMIN_CONTACT_URL'),
        'admin_username' => env('TELEGRAM_ADMIN_USERNAME'),
        'admin_telegram_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'webhook_rate_limit_per_minute' => env('TELEGRAM_WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
    ],

];
