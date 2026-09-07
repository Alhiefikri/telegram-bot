<?php

use App\Services\Bsc\BscReadiness;

$sakurupiahRuntime = (string) env('APP_ENV', 'production');
$sakurupiahApplicationUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$sakurupiahApplicationParts = parse_url($sakurupiahApplicationUrl);
$sakurupiahApplicationHost = is_array($sakurupiahApplicationParts)
    ? strtolower(rtrim((string) ($sakurupiahApplicationParts['host'] ?? ''), '.'))
    : '';
$sakurupiahApplicationOrigin = is_array($sakurupiahApplicationParts)
    && strtolower((string) ($sakurupiahApplicationParts['scheme'] ?? '')) === 'https'
    && $sakurupiahApplicationHost !== ''
    && $sakurupiahApplicationHost !== 'lhiestore.web.id'
    && ! array_key_exists('user', $sakurupiahApplicationParts)
    && ! array_key_exists('pass', $sakurupiahApplicationParts)
    && ! array_key_exists('port', $sakurupiahApplicationParts)
    && ! array_key_exists('query', $sakurupiahApplicationParts)
    && ! array_key_exists('fragment', $sakurupiahApplicationParts)
        ? 'https://'.$sakurupiahApplicationHost
        : null;

$sakurupiahSandboxCallbackUrl = $sakurupiahApplicationOrigin === null
    ? null
    : $sakurupiahApplicationOrigin.'/api/webhook/sakurupiah';
$sakurupiahSandboxReturnUrl = $sakurupiahApplicationOrigin === null
    ? null
    : $sakurupiahApplicationOrigin.'/shop/orders';
$sakurupiahSandboxApplicationHosts = $sakurupiahApplicationOrigin === null
    ? []
    : [$sakurupiahApplicationHost];

$sakurupiahSandboxRuntimeEnabled = in_array($sakurupiahRuntime, ['local', 'testing'], true);
$sakurupiahProductionApiId = env('SAKURUPIAH_PRODUCTION_API_ID');
$sakurupiahProductionApiKey = env('SAKURUPIAH_PRODUCTION_API_KEY');
$sakurupiahProductionEnabled = is_string($sakurupiahProductionApiId)
    && is_string($sakurupiahProductionApiKey)
    && trim($sakurupiahProductionApiId) !== ''
    && trim($sakurupiahProductionApiKey) !== '';

return [
    'credentials' => [
        // Optional override. When omitted, CredentialIdentityFingerprint
        // creates a stable key in persistent application storage.
        'identity_fingerprint_key' => env('CREDENTIAL_IDENTITY_FINGERPRINT_KEY'),
        'identity_fingerprint_key_path' => storage_path('app/private/credential-identity-fingerprint.key'),
    ],

    'bsc' => [
        // The RPC endpoint is the only BSC environment value because it may
        // contain provider credentials. Network identity and operational
        // limits are pinned here.
        'rpc_url' => env('BSC_RPC_URL'),
        'chain_id' => 56,
        'usdt_contract' => BscReadiness::APPROVED_USDT_CONTRACT,
        'explorer_base_url' => 'https://bscscan.com',
        'explorer_allowed_hosts' => ['bscscan.com', 'www.bscscan.com'],
        'expected_token_decimals' => 18,
        'rpc_timeout_seconds' => 5,
        'rpc_connect_timeout_seconds' => 2,
        'rpc_max_attempts' => 2,
        'rpc_retry_delay_ms' => 150,
        'max_scan_blocks' => 10_000,
        'max_scan_logs' => 1_000,
        'pending_backlog_alert_threshold' => 50,
        'late_payment_lookback_hours' => 72,
        'owner_alert_cooldown_seconds' => 900,
        'readiness_cache_seconds' => 60,
        'ownership_challenge_ttl_minutes' => 10,
        'max_unique_amount_candidates' => 1000,
    ],

    'binance' => [
        // Account identifiers are deployment inputs; API credentials remain
        // server-side secrets. Stable transport and retry budgets are pinned.
        'pay_id' => env('BINANCE_PAY_ID'),
        'pay_uid' => env('BINANCE_PAY_UID'),
        'api_key' => env('BINANCE_API_KEY'),
        'api_secret' => env('BINANCE_API_SECRET'),
        'base_url' => 'https://api.binance.com',
        'http_timeout_seconds' => 10.0,
        'http_connect_timeout_seconds' => 3.0,
        'recv_window_ms' => 10_000,
        'history_max_attempts' => 3,
        'history_retry_delay_ms' => 250,
        'history_cache_seconds' => 20,
        'history_lock_seconds' => 90,
        'history_max_pages' => 8,
        'history_max_window_splits' => 8,
        'history_max_duration_seconds' => 45,
        'verify_clock_skew_seconds' => 0,
        'verify_rate_limit_attempts' => 5,
        'verify_rate_limit_decay_seconds' => 300,
        'verification_queue' => 'binance-verification',
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
        'base_url' => 'https://app.pakasir.com',
        'http_timeout_seconds' => 10.0,
        'http_connect_timeout_seconds' => 3.0,
        'transaction_detail_max_attempts' => 3,
        'transaction_detail_retry_delay_ms' => 250,
        'transaction_detail_retry_max_delay_ms' => 1_000,
        'reconcile_released_lookback_hours' => 72,
        'redirect_url' => $sakurupiahApplicationUrl,
        'webhook_rate_limit_per_minute' => 30,
        'status_refresh_rate_limit_per_minute' => 3,
        'status_refresh_lock_lease_seconds' => 15,
        'status_refresh_single_flight_window_seconds' => 5,
        'status_refresh_in_progress_retry_after_seconds' => 3,
        'qris_fee' => [
            // Official Pakasir pricing published 2026-07-21.
            'payer' => 'customer',
            'threshold' => 105_000,
            'low_bps' => 70,
            'low_fixed' => 310,
            'high_bps' => 100,
            'high_fixed' => 0,
        ],
    ],

    'klikqris' => [
        // Feature switches are reviewed code settings, not ENV toggles.
        'sandbox_enabled' => $sakurupiahSandboxRuntimeEnabled,
        'sandbox_base_url' => 'https://klikqris.com/api/sandbox',
        'sandbox_api_key' => env('KLIKQRIS_SANDBOX_API_KEY'),
        'sandbox_merchant_id' => env('KLIKQRIS_SANDBOX_MERCHANT_ID'),
        'sandbox_callback_url' => $sakurupiahApplicationOrigin === null
            ? null
            : $sakurupiahApplicationOrigin.'/api/webhook/klikqris/sandbox',
        'sandbox_callback_allowed_hosts' => $sakurupiahSandboxApplicationHosts,
        'production_enabled' => false,
        'production_base_url' => 'https://klikqris.com/api',
        'production_api_key' => env('KLIKQRIS_PRODUCTION_API_KEY'),
        'production_merchant_id' => env('KLIKQRIS_PRODUCTION_MERCHANT_ID'),
        'production_callback_url' => 'https://lhiestore.web.id/api/webhook/klikqris',
        'production_allowed_hosts' => ['klikqris.com'],
        'production_callback_allowed_hosts' => ['lhiestore.web.id'],
        'qris_ttl_minutes' => 15,
        'http_timeout_seconds' => 10.0,
        'http_connect_timeout_seconds' => 3.0,
        'status_max_attempts' => 3,
        'recovery_alert_max_attempts' => 3,
        'status_retry_delay_ms' => 250,
        'webhook_rate_limit_per_minute' => 30,
        'status_refresh_rate_limit_per_minute' => 3,
        'reconcile_terminal_lookback_hours' => 72,
        'reconcile_max_execution_seconds' => 45.0,
        'reconcile_cursor_scan_cap' => 500,
        'reconciliation_command' => 'klikqris:reconcile --chunk=100',
        'reporting_cache_seconds' => 15,
        'reporting_max_limit' => 100,
    ],

    'sakurupiah' => [
        // Only Sakurupiah-specific ENV inputs are merchant credential pairs.
        // Global APP_ENV/APP_URL remain shared application configuration;
        // stable provider and safety settings are pinned in this release and
        // the Admin Payment Provider Switch controls new checkout traffic.
        'sandbox_enabled' => $sakurupiahSandboxRuntimeEnabled,
        'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
        'sandbox_api_id' => env('SAKURUPIAH_SANDBOX_API_ID'),
        'sandbox_api_key' => env('SAKURUPIAH_SANDBOX_API_KEY'),
        'sandbox_callback_url' => $sakurupiahSandboxCallbackUrl,
        'sandbox_return_url' => $sakurupiahSandboxReturnUrl,
        'sandbox_allowed_hosts' => ['sakurupiah.id'],
        'sandbox_callback_allowed_hosts' => $sakurupiahSandboxApplicationHosts,
        'sandbox_return_allowed_hosts' => $sakurupiahSandboxApplicationHosts,
        'sandbox_allowed_ips' => [],
        // Production is enabled automatically when its complete credential
        // pair is present. Stable provider settings stay pinned in code.
        'production_enabled' => $sakurupiahProductionEnabled,
        'production_base_url' => 'https://sakurupiah.id/api/',
        'production_api_id' => $sakurupiahProductionApiId,
        'production_api_key' => $sakurupiahProductionApiKey,
        'production_callback_url' => 'https://lhiestore.web.id/api/webhook/sakurupiah',
        'production_return_url' => 'https://lhiestore.web.id/shop/orders',
        'production_allowed_hosts' => ['sakurupiah.id'],
        'production_callback_allowed_hosts' => ['lhiestore.web.id'],
        'production_return_allowed_hosts' => ['lhiestore.web.id'],
        // This optional list is validated when configured but does not block
        // activation; provider credentials are the production switch.
        'production_allowed_ips' => [],
        'timezone' => 'Asia/Jakarta',
        'callback_expired_status_codes' => [2],
        'qris_contract' => [
            'currency' => 'IDR',
            'currency_confirmed' => true,
            'fee_payer' => 'merchant',
            'merchant_fee' => 1,
            'fee_confirmed' => true,
            'expiry_hours' => 12,
            'expiry_confirmed' => true,
        ],
        'http_timeout_seconds' => 10.0,
        'http_connect_timeout_seconds' => 3.0,
        'lookup_max_attempts' => 3,
        'lookup_retry_delay_ms' => 250,
        'lookup_retry_max_delay_ms' => 1000,
        'lock_lease_seconds' => 90,
        'webhook_rate_limit_per_minute' => 30,
        'status_refresh_rate_limit_per_minute' => 3,
        'readiness_cache_seconds' => 60,
        'reconciliation_command' => 'sakurupiah:reconcile --chunk=100',
        'reconcile_terminal_lookback_hours' => 72,
        'reconcile_max_execution_seconds' => 45,
        // The existing default worker is supervised in production. Do not
        // introduce a queue name whose worker is not served by the release.
        'verification_queue' => 'default',
    ],

    // Verification budgets intentionally differ by caller. Interactive
    // surfaces get one visible retry, webhook work is bounded before it is
    // acknowledged, and reconciliation is allowed to be persistent.
    'provider_verification' => [
        'interactive' => [
            'timeout_seconds' => 3.0,
            'connect_timeout_seconds' => 1.0,
            'max_attempts' => 2,
            'retry_delay_ms' => 200,
            'retry_max_delay_ms' => 200,
            'lock_safety_margin_seconds' => 3,
        ],
        'webhook' => [
            'timeout_seconds' => 3.0,
            'connect_timeout_seconds' => 1.0,
            'max_attempts' => 2,
            'retry_delay_ms' => 200,
            'retry_max_delay_ms' => 200,
            'lock_safety_margin_seconds' => 3,
        ],
        'reconciliation' => [
            'timeout_seconds' => 10.0,
            'connect_timeout_seconds' => 3.0,
            'max_attempts' => 3,
            'retry_delay_ms' => 250,
            'retry_max_delay_ms' => 1_000,
            'lock_safety_margin_seconds' => 8,
        ],
    ],

    // Existing deployments retain hardened behavior; new deployments can begin
    // in shadow while operators reconcile in-flight Payments before switching.
    'payment_lifecycle_rollout' => [
        'mode' => 'enabled',
        'canary_percentage' => 0,
        'stale_claim_minutes' => 15,
    ],

    'storefront' => [
        'checkout_rate_limit_per_minute' => 5,
        'binance_order_id_rate_limit_per_minute' => 5,
        'bsc_tx_hash_rate_limit_per_minute' => 5,
        // The status endpoint allows three requests per minute by default.
        // Keep automatic polling at or above 20 seconds to stay within it.
        'payment_status_poll_interval_seconds' => 20,
        'payment_status_poll_max_attempts' => 9,
        'payment_status_poll_max_duration_seconds' => 180,
        'telegram_reservation_floor' => 1,
        'invoice_url_ttl_days' => 7,
        'credential_access_rate_limit_per_minute' => 5,
        'credential_access_session_minutes' => 30,
        // Optional HMAC override; APP_KEY remains the fallback.
        'waitlist_hash_key' => env('STOREFRONT_WAITLIST_HASH_KEY'),
        'waitlist_confirmation_ttl_minutes' => 60,
        'waitlist_unsubscribe_ttl_days' => 30,
        'waitlist_max_recipients_per_event' => 500,
        'waitlist_ip_rate_limit_per_hour' => 30,
        'waitlist_email_rate_limit_per_hour' => 5,
        'waitlist_product_rate_limit_per_hour' => 100,
    ],

    'support' => [
        // Editable support destinations live in ManualPaymentSetting.
        'whatsapp_url' => null,
        'whatsapp_number' => null,
        'telegram_admin_url' => null,
        'telegram_admin_username' => 'alhie02',
    ],

    'customer' => [
        'login_link_expiry_minutes' => 15,
    ],

    'data_retention_days' => 90,

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => 'https://api.telegram.org',
        'http_timeout_seconds' => 30.0,
        'http_connect_timeout_seconds' => 10.0,
        'webhook_fast_callback_ack' => true,
        'payment_mode' => 'manual',
        'admin_contact_url' => null,
        'admin_username' => 'alhie02',
        'admin_user_id' => env('TELEGRAM_ADMIN_USER_ID'),
        'admin_telegram_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'mini_app_url' => $sakurupiahApplicationUrl.'/admin',
        'welcome_banner_url' => null,
        'start_metrics_cache_seconds' => 60,
        'webhook_rate_limit_per_minute' => 120,
        'community_gate_cache_seconds' => 15,
        'claim_lease_seconds' => 600,
        'processing_max_attempts' => 3,
        'processing_retry_delay_seconds' => 60,
        'broadcast_rate_limit_per_second' => 25,
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
    ],

];
