<?php

namespace App\Providers;

use App\Bootstrap\ApplicationRateLimiterRegistrar;
use App\Bootstrap\ProductionConfigurationValidator;
use App\Models\Category;
use App\Models\Product;
use App\Observers\ProductAuditObserver;
use App\Observers\StorefrontCacheInvalidationObserver;
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
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        $this->app->make(ProductionConfigurationValidator::class)->validate();
        $this->configureUrlGeneration();
        Vite::createAssetPathsUsing(fn (string $path, ?bool $secure): string => '/'.ltrim($path, '/'));

        Product::observe(ProductAuditObserver::class);
        Product::observe(StorefrontCacheInvalidationObserver::class);
        Category::observe(StorefrontCacheInvalidationObserver::class);

        DB::listen(fn ($query) => DogfoodTiming::recordQuery($query));

        $this->app->make(ApplicationRateLimiterRegistrar::class)->register();
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
