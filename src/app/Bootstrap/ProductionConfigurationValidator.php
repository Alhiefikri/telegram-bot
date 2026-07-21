<?php

namespace App\Bootstrap;

use RuntimeException;

class ProductionConfigurationValidator
{
    public function validate(): void
    {
        if (in_array(config('app.env'), ['local', 'testing'], true)) {
            return;
        }

        $required = [
            'APP_KEY' => config('app.key'),
            'TELEGRAM_BOT_TOKEN' => config('services.telegram.token'),
            'TELEGRAM_WEBHOOK_SECRET' => config('services.telegram.webhook_secret'),
        ];

        foreach ($required as $name => $value) {
            if (blank($value)) {
                throw new RuntimeException("{$name} must be configured in production.");
            }
        }

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
}
