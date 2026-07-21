<?php

namespace App\Bootstrap;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

class ApplicationRateLimiterRegistrar
{
    public function register(): void
    {
        RateLimiter::for('telegram', function (Request $request) {
            $limit = (int) config('services.telegram.webhook_rate_limit_per_minute', 120);

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

        RateLimiter::for('pakasir-webhook', function (Request $request) {
            $limit = (int) config('services.pakasir.webhook_rate_limit_per_minute', 30);
            $source = $request->ip() ?: 'unknown';

            return Limit::perMinute($limit)->by('pakasir:webhook:source:'.$source);
        });

        RateLimiter::for('pakasir-status-refresh', function (Request $request) {
            $limit = max(1, (int) config('services.pakasir.status_refresh_rate_limit_per_minute', 3));
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

            return Limit::perMinute($limit)->by('pakasir:status-refresh:'.$identity)->response($rateLimitedResponse);
        });

        RateLimiter::for('storefront-checkout', function (Request $request) {
            $limit = max(1, (int) config('services.storefront.checkout_rate_limit_per_minute', 5));
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

        RateLimiter::for('storefront-order-access', function (Request $request) {
            $limit = max(1, (int) config('services.storefront.credential_access_rate_limit_per_minute', 5));
            $source = $request->ip() ?: 'unknown';
            $invoice = strtoupper(trim((string) $request->input('invoice_number', 'unknown')));

            return [
                Limit::perMinute($limit)->by('storefront:order-access:source:'.$source),
                Limit::perMinute($limit)->by('storefront:order-access:invoice:'.$invoice),
            ];
        });

        RateLimiter::for('customer-login-link', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', 'unknown')));
            $ip = $request->ip() ?: 'unknown';

            return [Limit::perMinutes(15, 3)->by('customer:login:email:'.$email.':'.$ip), Limit::perHour(10)->by('customer:login:ip:'.$ip)];
        });

        RateLimiter::for('customer-login-consume', fn (Request $request) => Limit::perMinutes(15, 10)->by('customer:login:consume:'.($request->ip() ?: 'unknown')));
        RateLimiter::for('customer-order-claim', fn (Request $request) => Limit::perMinutes(15, 5)->by('customer:claim:'.($request->user('customer')?->getAuthIdentifier() ?? 'unknown').':'.($request->ip() ?: 'unknown')));
    }

    private function hasTrustedTelegramWebhookSecret(Request $request): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        return $secret === ''
            || hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }
}
