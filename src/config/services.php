<?php

return [
    'credentials' => [
        // Optional override. When omitted, CredentialIdentityFingerprint
        // creates a stable key in persistent application storage.
        'identity_fingerprint_key' => env('CREDENTIAL_IDENTITY_FINGERPRINT_KEY'),
        'identity_fingerprint_key_path' => storage_path('app/private/credential-identity-fingerprint.key'),
    ],

    'bsc' => [
        // The receiver pool is database-managed. These are the only BSC
        // deployment values that belong in environment configuration.
        'rpc_url' => env('BSC_RPC_URL'),
        'chain_id' => (int) env('BSC_CHAIN_ID', 56),
        'usdt_contract' => env('BSC_USDT_CONTRACT'),
        'explorer_base_url' => env('BSC_EXPLORER_BASE_URL', 'https://bscscan.com'),
        'explorer_allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('BSC_EXPLORER_ALLOWED_HOSTS', 'bscscan.com,www.bscscan.com')),
        ))),
        'expected_token_decimals' => (int) env('BSC_USDT_DECIMALS', 18),
        'rpc_timeout_seconds' => (float) env('BSC_RPC_TIMEOUT_SECONDS', 5),
        'rpc_connect_timeout_seconds' => (float) env('BSC_RPC_CONNECT_TIMEOUT_SECONDS', 2),
        'rpc_max_attempts' => (int) env('BSC_RPC_MAX_ATTEMPTS', 2),
        'rpc_retry_delay_ms' => (int) env('BSC_RPC_RETRY_DELAY_MS', 150),
        'max_scan_blocks' => (int) env('BSC_MAX_SCAN_BLOCKS', 10_000),
        'max_scan_logs' => (int) env('BSC_MAX_SCAN_LOGS', 1_000),
        'pending_backlog_alert_threshold' => (int) env('BSC_PENDING_BACKLOG_ALERT_THRESHOLD', 50),
        'late_payment_lookback_hours' => (int) env('BSC_LATE_PAYMENT_LOOKBACK_HOURS', 72),
        'owner_alert_cooldown_seconds' => (int) env('BSC_OWNER_ALERT_COOLDOWN_SECONDS', 900),
        'ownership_challenge_ttl_minutes' => 10,
        'max_unique_amount_candidates' => 1000,
    ],

    'binance' => [
        'pay_id' => env('BINANCE_PAY_ID'),
        'pay_uid' => env('BINANCE_PAY_UID'),
        'api_key' => env('BINANCE_API_KEY'),
        'api_secret' => env('BINANCE_API_SECRET'),
        'base_url' => env('BINANCE_API_BASE_URL', 'https://api.binance.com'),
        'http_timeout_seconds' => (float) env('BINANCE_HTTP_TIMEOUT_SECONDS', 10),
        'http_connect_timeout_seconds' => (float) env('BINANCE_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        'recv_window_ms' => (int) env('BINANCE_RECV_WINDOW_MS', 10000),
        'history_max_attempts' => (int) env('BINANCE_HISTORY_MAX_ATTEMPTS', 3),
        'history_retry_delay_ms' => (int) env('BINANCE_HISTORY_RETRY_DELAY_MS', 250),
        'history_cache_seconds' => (int) env('BINANCE_HISTORY_CACHE_SECONDS', 20),
        'history_lock_seconds' => (int) env('BINANCE_HISTORY_LOCK_SECONDS', 90),
        'history_max_pages' => (int) env('BINANCE_HISTORY_MAX_PAGES', 8),
        'history_max_window_splits' => (int) env('BINANCE_HISTORY_MAX_WINDOW_SPLITS', 8),
        'history_max_duration_seconds' => (int) env('BINANCE_HISTORY_MAX_DURATION_SECONDS', 45),
        'verify_clock_skew_seconds' => (int) env('BINANCE_VERIFY_CLOCK_SKEW_SECONDS', 0),
        'verify_rate_limit_attempts' => (int) env('BINANCE_VERIFY_RATE_LIMIT_ATTEMPTS', 5),
        'verify_rate_limit_decay_seconds' => (int) env('BINANCE_VERIFY_RATE_LIMIT_DECAY_SECONDS', 300),
        'verification_queue' => env('BINANCE_VERIFICATION_QUEUE', 'binance-verification'),
    ],

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
        'status_refresh_lock_lease_seconds' => (int) env('PAKASIR_STATUS_REFRESH_LOCK_LEASE_SECONDS', 15),
        'status_refresh_single_flight_window_seconds' => (int) env('PAKASIR_STATUS_REFRESH_SINGLE_FLIGHT_WINDOW_SECONDS', 5),
        'status_refresh_in_progress_retry_after_seconds' => (int) env('PAKASIR_STATUS_REFRESH_IN_PROGRESS_RETRY_AFTER_SECONDS', 3),
        'qris_fee' => [
            // Official Pakasir pricing published 2026-07-21.
            'payer' => env('PAKASIR_QRIS_FEE_PAYER', 'customer'),
            'threshold' => (int) env('PAKASIR_QRIS_FEE_THRESHOLD', 105000),
            'low_bps' => (int) env('PAKASIR_QRIS_FEE_LOW_BPS', 70),
            'low_fixed' => (int) env('PAKASIR_QRIS_FEE_LOW_FIXED', 310),
            'high_bps' => (int) env('PAKASIR_QRIS_FEE_HIGH_BPS', 100),
            'high_fixed' => (int) env('PAKASIR_QRIS_FEE_HIGH_FIXED', 0),
        ],
    ],

    'klikqris' => [
        'sandbox_enabled' => (bool) env('KLIKQRIS_SANDBOX_ENABLED', false),
        'sandbox_base_url' => env('KLIKQRIS_SANDBOX_BASE_URL', 'https://klikqris.com/api/sandbox'),
        'sandbox_api_key' => env('KLIKQRIS_SANDBOX_API_KEY'),
        'sandbox_merchant_id' => env('KLIKQRIS_SANDBOX_MERCHANT_ID'),
        'sandbox_callback_url' => env('KLIKQRIS_SANDBOX_CALLBACK_URL'),
        'sandbox_callback_allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('KLIKQRIS_SANDBOX_CALLBACK_ALLOWED_HOSTS', '')),
        ))),
        'production_enabled' => (bool) env('KLIKQRIS_PRODUCTION_ENABLED', false),
        'production_base_url' => env('KLIKQRIS_PRODUCTION_BASE_URL'),
        'production_api_key' => env('KLIKQRIS_PRODUCTION_API_KEY'),
        'production_merchant_id' => env('KLIKQRIS_PRODUCTION_MERCHANT_ID'),
        'production_callback_url' => env('KLIKQRIS_PRODUCTION_CALLBACK_URL'),
        'production_allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('KLIKQRIS_PRODUCTION_ALLOWED_HOSTS', '')),
        ))),
        'production_callback_allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('KLIKQRIS_PRODUCTION_CALLBACK_ALLOWED_HOSTS', '')),
        ))),
        'qris_ttl_minutes' => (int) env('KLIKQRIS_QRIS_TTL_MINUTES', 15),
        'http_timeout_seconds' => (float) env('KLIKQRIS_HTTP_TIMEOUT_SECONDS', 10),
        'http_connect_timeout_seconds' => (float) env('KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        'status_max_attempts' => (int) env('KLIKQRIS_STATUS_MAX_ATTEMPTS', 3),
        'recovery_alert_max_attempts' => (int) env('KLIKQRIS_RECOVERY_ALERT_MAX_ATTEMPTS', 3),
        'status_retry_delay_ms' => (int) env('KLIKQRIS_STATUS_RETRY_DELAY_MS', 250),
        'webhook_rate_limit_per_minute' => (int) env('KLIKQRIS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 30),
        'status_refresh_rate_limit_per_minute' => (int) env('KLIKQRIS_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE', 3),
        'reconciliation_command' => 'klikqris:reconcile --chunk=100',
        'reporting_cache_seconds' => (int) env('KLIKQRIS_REPORTING_CACHE_SECONDS', 15),
        'reporting_max_limit' => (int) env('KLIKQRIS_REPORTING_MAX_LIMIT', 100),
    ],

    // Verification budgets intentionally differ by caller. Interactive
    // surfaces get one visible retry, webhook work is bounded before it is
    // acknowledged, and reconciliation is allowed to be persistent.
    'provider_verification' => [
        'interactive' => [
            'timeout_seconds' => (float) env('PROVIDER_VERIFY_INTERACTIVE_TIMEOUT_SECONDS', 3),
            'connect_timeout_seconds' => (float) env('PROVIDER_VERIFY_INTERACTIVE_CONNECT_TIMEOUT_SECONDS', 1),
            'max_attempts' => (int) env('PROVIDER_VERIFY_INTERACTIVE_MAX_ATTEMPTS', 2),
            'retry_delay_ms' => (int) env('PROVIDER_VERIFY_INTERACTIVE_RETRY_DELAY_MS', 200),
            'retry_max_delay_ms' => (int) env('PROVIDER_VERIFY_INTERACTIVE_RETRY_MAX_DELAY_MS', 200),
            'lock_safety_margin_seconds' => (int) env('PROVIDER_VERIFY_INTERACTIVE_LOCK_SAFETY_MARGIN_SECONDS', 3),
        ],
        'webhook' => [
            'timeout_seconds' => (float) env('PROVIDER_VERIFY_WEBHOOK_TIMEOUT_SECONDS', 3),
            'connect_timeout_seconds' => (float) env('PROVIDER_VERIFY_WEBHOOK_CONNECT_TIMEOUT_SECONDS', 1),
            'max_attempts' => (int) env('PROVIDER_VERIFY_WEBHOOK_MAX_ATTEMPTS', 2),
            'retry_delay_ms' => (int) env('PROVIDER_VERIFY_WEBHOOK_RETRY_DELAY_MS', 200),
            'retry_max_delay_ms' => (int) env('PROVIDER_VERIFY_WEBHOOK_RETRY_MAX_DELAY_MS', 200),
            'lock_safety_margin_seconds' => (int) env('PROVIDER_VERIFY_WEBHOOK_LOCK_SAFETY_MARGIN_SECONDS', 3),
        ],
        'reconciliation' => [
            'timeout_seconds' => (float) env('PROVIDER_VERIFY_RECONCILIATION_TIMEOUT_SECONDS', 10),
            'connect_timeout_seconds' => (float) env('PROVIDER_VERIFY_RECONCILIATION_CONNECT_TIMEOUT_SECONDS', 3),
            'max_attempts' => (int) env('PROVIDER_VERIFY_RECONCILIATION_MAX_ATTEMPTS', 3),
            'retry_delay_ms' => (int) env('PROVIDER_VERIFY_RECONCILIATION_RETRY_DELAY_MS', 250),
            'retry_max_delay_ms' => (int) env('PROVIDER_VERIFY_RECONCILIATION_RETRY_MAX_DELAY_MS', 1000),
            'lock_safety_margin_seconds' => (int) env('PROVIDER_VERIFY_RECONCILIATION_LOCK_SAFETY_MARGIN_SECONDS', 8),
        ],
    ],

    // Existing deployments retain hardened behavior; new deployments can begin
    // in shadow while operators reconcile in-flight Payments before switching.
    'payment_lifecycle_rollout' => [
        'mode' => env('PAYMENT_LIFECYCLE_ROLLOUT_MODE', 'enabled'),
        'canary_percentage' => (int) env('PAYMENT_LIFECYCLE_ROLLOUT_CANARY_PERCENTAGE', 0),
        'stale_claim_minutes' => (int) env('PAYMENT_LIFECYCLE_ROLLOUT_STALE_CLAIM_MINUTES', 15),
    ],

    'storefront' => [
        'checkout_rate_limit_per_minute' => (int) env('STOREFRONT_CHECKOUT_RATE_LIMIT_PER_MINUTE', 5),
        'binance_order_id_rate_limit_per_minute' => (int) env('STOREFRONT_BINANCE_ORDER_ID_RATE_LIMIT_PER_MINUTE', 5),
        'bsc_tx_hash_rate_limit_per_minute' => (int) env('STOREFRONT_BSC_TX_HASH_RATE_LIMIT_PER_MINUTE', 5),
        'telegram_reservation_floor' => (int) env('STOREFRONT_TELEGRAM_RESERVATION_FLOOR', 1),
        'invoice_url_ttl_days' => (int) env('STOREFRONT_INVOICE_URL_TTL_DAYS', 7),
        'credential_access_rate_limit_per_minute' => (int) env('STOREFRONT_CREDENTIAL_ACCESS_RATE_LIMIT_PER_MINUTE', 5),
        'credential_access_session_minutes' => (int) env('STOREFRONT_CREDENTIAL_ACCESS_SESSION_MINUTES', 30),
    ],

    'support' => [
        'whatsapp_url' => env('WHATSAPP_SUPPORT_URL'),
        'whatsapp_number' => env('WHATSAPP_SUPPORT_NUMBER'),
        'telegram_admin_url' => env('TELEGRAM_ADMIN_CONTACT_URL'),
        'telegram_admin_username' => env('TELEGRAM_ADMIN_USERNAME', 'alhie02'),
    ],

    'customer' => [
        'login_link_expiry_minutes' => 15,
    ],

    'data_retention_days' => (int) env('DATA_RETENTION_DAYS', 90),

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'http_timeout_seconds' => (float) env('TELEGRAM_HTTP_TIMEOUT_SECONDS', 30),
        'http_connect_timeout_seconds' => (float) env('TELEGRAM_HTTP_CONNECT_TIMEOUT_SECONDS', 10),
        'webhook_fast_callback_ack' => (bool) env('TELEGRAM_WEBHOOK_FAST_CALLBACK_ACK', true),
        'payment_mode' => 'manual',
        'admin_contact_url' => env('TELEGRAM_ADMIN_CONTACT_URL'),
        'admin_username' => env('TELEGRAM_ADMIN_USERNAME'),
        'admin_user_id' => env('TELEGRAM_ADMIN_USER_ID'),
        'admin_telegram_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
        'welcome_banner_url' => env('TELEGRAM_WELCOME_BANNER_URL'),
        'start_metrics_cache_seconds' => (int) env('TELEGRAM_START_METRICS_CACHE_SECONDS', 60),
        'webhook_rate_limit_per_minute' => env('TELEGRAM_WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
        'community_gate_cache_seconds' => (int) env('TELEGRAM_COMMUNITY_GATE_CACHE_SECONDS', 15),
        'claim_lease_seconds' => (int) env('TELEGRAM_CLAIM_LEASE_SECONDS', 600),
        'processing_max_attempts' => (int) env('TELEGRAM_PROCESSING_MAX_ATTEMPTS', 3),
        'processing_retry_delay_seconds' => (int) env('TELEGRAM_PROCESSING_RETRY_DELAY_SECONDS', 60),
        'broadcast_rate_limit_per_second' => (int) env('TELEGRAM_BROADCAST_RATE_LIMIT_PER_SECOND', 25),
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
