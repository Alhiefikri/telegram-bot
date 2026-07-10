<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Observers\ProductAuditObserver;
use App\Services\Admin\AdminAccessAuthorizer;
use App\Services\Admin\AdminAccessAuthorizerInterface;
use App\Services\Audit\AuditContext;
use App\Services\Audit\HttpAuditContext;
use App\Services\EloquentInventoryLedger;
use App\Services\InventoryLedger;
use App\Services\Pakasir\PakasirClient;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Storefront\StorefrontResponseCache;
use App\Support\DogfoodTiming;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InventoryLedger::class, EloquentInventoryLedger::class);
        $this->app->bind(AuditContext::class, HttpAuditContext::class);
        $this->app->bind(AdminAccessAuthorizerInterface::class, AdminAccessAuthorizer::class);
        $this->app->bind(PakasirProviderAdapter::class, PakasirClient::class);
        $this->app->bind(TelegramBotClient::class, $this->telegramBotClientClass());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateProductionConfiguration();
        $this->configureUrlGeneration();
        Vite::createAssetPathsUsing(fn (string $path, ?bool $secure): string => '/'.ltrim($path, '/'));

        Product::observe(ProductAuditObserver::class);
        $invalidateStorefront = fn () => app(StorefrontResponseCache::class)->invalidate();
        Product::saved($invalidateStorefront);
        Product::deleted($invalidateStorefront);
        Product::restored($invalidateStorefront);
        Category::saved($invalidateStorefront);
        Category::deleted($invalidateStorefront);
        Category::restored($invalidateStorefront);

        DB::listen(fn ($query) => DogfoodTiming::recordQuery($query));

        RateLimiter::for('telegram', function (Request $request) {
            $limit = (int) config('services.telegram.webhook_rate_limit_per_minute', 120);

            if (! $this->hasTrustedTelegramWebhookSecret($request)) {
                $source = $request->ip() ?: 'unknown';

                return [
                    Limit::perMinute($limit)->by('telegram:webhook:bad-secret:global'),
                    Limit::perMinute($limit)->by('telegram:webhook:bad-secret:source:'.$source),
                ];
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

            return [
                Limit::perMinute($limit)->by('pakasir:webhook:global'),
                Limit::perMinute($limit)->by('pakasir:webhook:source:'.$source),
            ];
        });

        RateLimiter::for('pakasir-status-refresh', function (Request $request) {
            $limit = max(1, (int) config('services.pakasir.status_refresh_rate_limit_per_minute', 3));
            $routeOrder = $request->route('order');
            $identity = is_object($routeOrder) && method_exists($routeOrder, 'getKey')
                ? (string) $routeOrder->getKey()
                : ($request->ip() ?: 'unknown');

            return Limit::perMinute($limit)->by('pakasir:status-refresh:'.$identity);
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

    }

    private function configureUrlGeneration(): void
    {
        if (in_array(config('app.env'), ['local', 'testing'], true) || $this->isLoopbackHttpRequest()) {
            return;
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    private function hasTrustedTelegramWebhookSecret(Request $request): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        return $secret === ''
            || hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    private function validateProductionConfiguration(): void
    {
        if (in_array(config('app.env'), ['local', 'testing'], true)) {
            return;
        }

        $required = [
            'APP_KEY' => config('app.key'),
            'TELEGRAM_BOT_TOKEN' => config('services.telegram.token'),
            'TELEGRAM_WEBHOOK_SECRET' => config('services.telegram.webhook_secret'),
        ];

        if ((bool) config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        $pakasirBaseUrl = (string) config('services.pakasir.base_url', '');
        if (! blank($pakasirBaseUrl) && ! str_starts_with($pakasirBaseUrl, 'https://')) {
            throw new RuntimeException('PAKASIR_BASE_URL must use HTTPS in production.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            throw new RuntimeException('APP_URL must use HTTPS in production.');
        }

        if (in_array(rtrim((string) config('app.url'), '/'), ['https://example.com'], true)) {
            throw new RuntimeException('APP_URL must not use the example domain in production.');
        }

        if (((bool) config('services.dogfood_timing.enabled') || (bool) config('services.dogfood_timing.fake_telegram_outbound')) && ! (bool) config('services.dogfood_timing.allow_runtime')) {
            throw new RuntimeException('Dogfood runtime flags are not allowed in production.');
        }
    }

    private function telegramBotClientClass(): string
    {
        if (($this->app->environment('testing') && ! $this->isPhpUnitProcess())
            || (bool) config('services.dogfood_timing.fake_telegram_outbound')) {
            return FakeTelegramBotClient::class;
        }

        return TelegramBotClient::class;
    }

    private function isPhpUnitProcess(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        return defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || str_ends_with($script, 'phpunit');
    }

    private function isLoopbackHttpRequest(): bool
    {
        $request = request();

        if ($request->isSecure()) {
            return false;
        }

        return in_array(trim($request->getHost(), '[]'), ['localhost', '127.0.0.1', '::1'], true);
    }
}
