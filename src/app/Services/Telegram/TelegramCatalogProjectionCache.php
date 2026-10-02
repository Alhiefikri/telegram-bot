<?php

namespace App\Services\Telegram;

use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\Cache;

final class TelegramCatalogProjectionCache
{
    private const VERSION_KEY = 'telegram.catalog.projection.version';

    private const TTL_MINUTES = 30;

    private const DESIGN_VERSION = 4;

    /**
     * Cache raw arrays, not read models: a cache store unserializes with
     * `cache.serializable_classes` set to false, so a stored object reads back
     * as PHP_Incomplete_Class, and a payload written by an older release must
     * never break a read — anything that is not the expected array is rebuilt.
     *
     * @param  array<string, scalar|null>  $dimensions
     * @param  Closure(): array<array-key, mixed>  $build
     * @return array<array-key, mixed>
     */
    public function remember(string $projection, array $dimensions, Closure $build): array
    {
        $version = $this->version();
        $key = 'catalog.public.projection.v'.$version.'.'.hash('sha256', json_encode([
            'design_version' => self::DESIGN_VERSION,
            'projection' => $projection,
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR));

        $payload = Cache::get($key);
        if (is_array($payload)) {
            return $payload;
        }

        $snapshotAt = now();
        $payload = $build();
        $expires = now()->addMinutes(self::TTL_MINUTES);
        $supplierUntil = Product::query()->where('supplier_available_stock', '>', 0)
            ->where('supplier_available_until', '>', $snapshotAt)->min('supplier_available_until');
        if ($supplierUntil !== null && \Illuminate\Support\Carbon::parse($supplierUntil)->lessThan($expires)) {
            $expires = \Illuminate\Support\Carbon::parse($supplierUntil);
        }
        Cache::put($key, $payload, $expires);

        return $payload;
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
