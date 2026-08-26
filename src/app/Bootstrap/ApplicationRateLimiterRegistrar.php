<?php

namespace App\Bootstrap;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ApplicationRateLimiterRegistrar
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly Repository $config,
    ) {}

    public function register(): void
    {
        $this->rateLimiter->for('telegram', function (Request $request) {
            $limit = (int) $this->config->get('services.telegram.webhook_rate_limit_per_minute', 120);

            if (! $this->hasTrustedTelegramWebhookSecret($request)) {
                $source = $request->ip() ?: 'unknown';

                return Limit::perMinute($limit)->by('telegram:webhook:bad-secret:source:'.$source);
            }

            $payload = $request->all();
            $identity = Arr::get($payload, 'message.from.id')
                ?? Arr::get($payload, 'callback_query.from.id')
                ?? Arr::get($payload, 'message.chat.id')
                ?? $request->ip()
                ?? 'unknown';

            if (! is_scalar($identity)) {
                $identity = $request->ip() ?: 'unknown';
            }

            return Limit::perMinute($limit)->by('telegram:webhook:identity:'.(string) $identity);
        });

        $this->rateLimiter->for('klikqris-sandbox-webhook', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.klikqris.webhook_rate_limit_per_minute', 30));
            $source = $request->ip() ?: 'unknown';

            return Limit::perMinute($limit)->by('klikqris:sandbox-webhook:source:'.$source);
        });

        $this->rateLimiter->for('klikqris-production-webhook', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.klikqris.webhook_rate_limit_per_minute', 30));
            $source = $request->ip() ?: 'unknown';

            return Limit::perMinute($limit)->by('klikqris:production-webhook:source:'.$source);
        });

        $this->rateLimiter->for('pakasir-webhook', function (Request $request) {
            $limit = (int) $this->config->get('services.pakasir.webhook_rate_limit_per_minute', 30);
            $source = $request->ip() ?: 'unknown';

            return Limit::perMinute($limit)->by('pakasir:webhook:source:'.$source);
        });

        $statusRefreshLimiter = function (Request $request, string $bucketPrefix = 'automatic-payment') {
            $limit = max(1, (int) $this->config->get('services.pakasir.status_refresh_rate_limit_per_minute', 3));
            $routeOrder = $request->route('order');
            $identity = is_object($routeOrder) && method_exists($routeOrder, 'getKey')
                ? (string) $routeOrder->getKey()
                : ($request->ip() ?: 'unknown');
            $rateLimitedResponse = function (Request $request, array $headers) {
                $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

                return response()->json([
                    'ok' => false,
                    'retry_after' => $retryAfter,
                    'message' => 'Terlalu sering cek status. Coba lagi dalam '.$retryAfter.' detik.',
                ], 429, $headers);
            };

            $source = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute($limit)->by($bucketPrefix.':status-refresh:order:'.$identity)->response($rateLimitedResponse),
                Limit::perMinute($limit)->by($bucketPrefix.':status-refresh:source:'.$source)->response($rateLimitedResponse),
            ];
        };

        $this->rateLimiter->for('automatic-payment-status-refresh', $statusRefreshLimiter);
        $this->rateLimiter->for('pakasir-status-refresh', fn (Request $request) => $statusRefreshLimiter($request, 'pakasir'));

        $this->rateLimiter->for('storefront-checkout', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.storefront.checkout_rate_limit_per_minute', 5));
            $source = $request->ip() ?: 'unknown';
            $session = $request->hasSession() ? $request->session()->getId() : 'no-session';

            $rateLimitedResponse = function (Request $request, array $headers) {
                $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

                return response()->view('errors.429', [
                    'retryAfter' => $retryAfter,
                ], 429, $headers);
            };

            return [
                Limit::perMinute($limit)->by('storefront:checkout:source:'.$source)->response($rateLimitedResponse),
                Limit::perMinute($limit)->by('storefront:checkout:session:'.$session)->response($rateLimitedResponse),
            ];
        });

        $this->rateLimiter->for('storefront-verified-purchase-activity', function (Request $request) {
            $source = $request->ip() ?: 'unknown';

            return Limit::perMinute(12)->by('storefront:verified-purchase-activity:'.$source);
        });

        $this->rateLimiter->for('storefront-binance-order-id', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.storefront.binance_order_id_rate_limit_per_minute', 5));
            $source = $request->ip() ?: 'unknown';
            $routeOrder = $request->route('order');
            $order = is_object($routeOrder) && method_exists($routeOrder, 'getKey')
                ? (string) $routeOrder->getKey()
                : 'unknown';

            return [
                Limit::perMinute($limit)->by('storefront:binance-order-id:order:'.$order),
                Limit::perMinute($limit * 3)->by('storefront:binance-order-id:source:'.$source),
            ];
        });

        $this->rateLimiter->for('storefront-bsc-tx-hash', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.storefront.bsc_tx_hash_rate_limit_per_minute', 5));
            $source = $request->ip() ?: 'unknown';
            $routeOrder = $request->route('order');
            $order = is_object($routeOrder) && method_exists($routeOrder, 'getKey')
                ? (string) $routeOrder->getKey()
                : 'unknown';

            return [
                Limit::perMinute($limit)->by('storefront:bsc-tx-hash:order:'.$order),
                Limit::perMinute($limit * 3)->by('storefront:bsc-tx-hash:source:'.$source),
            ];
        });

        $this->rateLimiter->for('storefront-order-access', function (Request $request) {
            $limit = max(1, (int) $this->config->get('services.storefront.credential_access_rate_limit_per_minute', 5));
            $source = $request->ip() ?: 'unknown';
            $invoice = strtoupper(trim((string) $request->input('invoice_number', 'unknown')));

            return [
                Limit::perMinute($limit)->by('storefront:order-access:source:'.$source),
                Limit::perMinute($limit)->by('storefront:order-access:invoice:'.$invoice),
            ];
        });

        $this->rateLimiter->for('customer-login-link', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', 'unknown')));
            $ip = $request->ip() ?: 'unknown';

            return [Limit::perMinutes(15, 3)->by('customer:login:email:'.$email.':'.$ip), Limit::perHour(10)->by('customer:login:ip:'.$ip)];
        });

        $this->rateLimiter->for('customer-login-consume', fn (Request $request) => Limit::perMinutes(15, 10)->by('customer:login:consume:'.($request->ip() ?: 'unknown')));
        $this->rateLimiter->for('customer-order-claim', fn (Request $request) => Limit::perMinutes(15, 5)->by('customer:claim:'.($request->user('customer')?->getAuthIdentifier() ?? 'unknown').':'.($request->ip() ?: 'unknown')));
    }

    private function hasTrustedTelegramWebhookSecret(Request $request): bool
    {
        $secret = (string) $this->config->get('services.telegram.webhook_secret');

        return $secret === ''
            || hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }
}
