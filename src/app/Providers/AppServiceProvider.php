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
        $this->app->bind(TelegramBotClient::class, config('services.dogfood_timing.fake_telegram_outbound')
            ? FakeTelegramBotClient::class
            : TelegramBotClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateProductionConfiguration();

        Product::observe(ProductAuditObserver::class);
        $invalidateStorefront = fn () => app(StorefrontResponseCache::class)->invalidate();
        Product::saved($invalidateStorefront);
        Product::deleted($invalidateStorefront);
        Product::restored($invalidateStorefront);
        Category::saved($invalidateStorefront);
        Category::deleted($invalidateStorefront);
        Category::restored($invalidateStorefront);

        DB::listen(fn ($query) => DogfoodTiming::recordQuery($query));

        if ((bool) config('app.force_https') && ! in_array(config('app.env'), ['local', 'testing'], true) && ! $this->isLoopbackHttpRequest()) {
            URL::forceScheme('https');
        }

        RateLimiter::for('telegram', function (Request $request) {
            $payload = $request->all();
            $key = Arr::get($payload, 'message.from.id')
                ?? Arr::get($payload, 'callback_query.from.id')
                ?? Arr::get($payload, 'message.chat.id')
                ?? $request->ip();

            return Limit::perMinute((int) config('services.telegram.webhook_rate_limit_per_minute', 120))->by('telegram:'.$key);
        });

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

        if (config('services.telegram.payment_mode') === 'pakasir') {
            $required += [
                'PAKASIR_SLUG' => config('services.pakasir.slug'),
                'PAKASIR_API_KEY' => config('services.pakasir.api_key'),
            ];
        }

        $missing = array_keys(array_filter($required, fn ($value) => blank($value)));

        if ($missing !== []) {
            throw new RuntimeException('Missing required production configuration: '.implode(', ', $missing));
        }

        if (config('services.telegram.payment_mode') === 'pakasir' && ! str_starts_with((string) config('services.pakasir.base_url'), 'https://')) {
            throw new RuntimeException('PAKASIR_BASE_URL must use HTTPS in production.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            throw new RuntimeException('APP_URL must use HTTPS in production.');
        }
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
