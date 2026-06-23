<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ((bool) config('app.force_https') && ! in_array(config('app.env'), ['local', 'testing'], true)) {
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

        RateLimiter::for('tripay', function (Request $request) {
            $payload = $request->all();
            $key = Arr::get($payload, 'merchant_ref')
                ?? Arr::get($payload, 'reference')
                ?? Arr::get($payload, 'provider_reference')
                ?? $request->ip();

            return Limit::perMinute((int) config('services.tripay.webhook_rate_limit_per_minute', 30))->by('tripay:'.$key);
        });
    }
}
