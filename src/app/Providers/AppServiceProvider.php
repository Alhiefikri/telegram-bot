<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateProductionConfiguration();

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

        RateLimiter::for('tripay', function (Request $request) {
            $limit = (int) config('services.tripay.webhook_rate_limit_per_minute', 30);

            return Limit::perMinute($limit)->by('tripay:ip:'.$request->ip());
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

        if (config('services.telegram.payment_mode') === 'tripay') {
            $required += [
                'TRIPAY_API_KEY' => config('services.tripay.api_key'),
                'TRIPAY_PRIVATE_KEY' => config('services.tripay.private_key'),
                'TRIPAY_MERCHANT_CODE' => config('services.tripay.merchant_code'),
                'TRIPAY_CALLBACK_URL' => config('services.tripay.callback_url'),
                'TRIPAY_RETURN_URL' => config('services.tripay.return_url'),
            ];
        }

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
