<?php

namespace App\Services\Telegram;

use Closure;
use Illuminate\Support\Facades\Cache;

final class TelegramCatalogProjectionCache
{
    private const VERSION_KEY = 'telegram.catalog.projection.version';

    private const TTL_MINUTES = 30;

    private const DESIGN_VERSION = 1;

    public function remember(string $projection, array $dimensions, Closure $build): mixed
    {
        $version = $this->version();
        $key = 'catalog.public.projection.v'.$version.'.'.hash('sha256', json_encode([
            'design_version' => self::DESIGN_VERSION,
            'projection' => $projection,
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), $build);
    }

    public function invalidate(): void
    {
        Cache::add(self::VERSION_KEY, 1, now()->addYears(5));
        Cache::increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        Cache::add(self::VERSION_KEY, 1, now()->addYears(5));

        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
