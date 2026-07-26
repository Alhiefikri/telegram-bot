<?php

namespace App\Storefront;

use App\Support\DogfoodTiming;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class StorefrontResponseCache
{
    private const VERSION_KEY = 'storefront.response.version';

    private const CACHE_TTL_MINUTES = 30;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    private const DESIGN_VERSION = 8;

    public function __construct(private readonly StorefrontCatalogPageBuilder $catalogPages) {}

    public function get(string $search): string
    {
        $search = StorefrontCatalogSearch::normalize($search);
        $startedAt = DogfoodTiming::now();
        $querySnapshot = DogfoodTiming::querySnapshot();

        if ($search !== '') {
            $html = $this->render($search);
            $this->log($startedAt, $querySnapshot, $search, false, strlen($html), 'search_uncached');

            return $html;
        }

        $version = $this->version();
        $key = $this->key($version, $search);

        $cached = Cache::get($key);
        if (is_string($cached)) {
            $this->log($startedAt, $querySnapshot, $search, true, strlen($cached));

            return $cached;
        }

        try {
            $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $html = $this->render($search);
            $this->log($startedAt, $querySnapshot, $search, false, strlen($html), 'lock_timeout');

            return $html;
        }

        try {
            $cached = Cache::get($key);
            if (is_string($cached)) {
                $this->log($startedAt, $querySnapshot, $search, true, strlen($cached), 'after_lock');

                return $cached;
            }

            $html = $this->render($search);
            Cache::put($key, $html, now()->addMinutes(self::CACHE_TTL_MINUTES));
            $this->log($startedAt, $querySnapshot, $search, false, strlen($html));

            return $html;
        } finally {
            $lock->release();
        }
    }

    public function invalidate(): void
    {
        Cache::add(self::VERSION_KEY, 1, now()->addYears(5));
        Cache::increment(self::VERSION_KEY);
        $this->deleteStaticHomepage();
    }

    private function version(): int
    {
        Cache::add(self::VERSION_KEY, 1, now()->addYears(5));

        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    private function key(int $version, string $search): string
    {
        $context = [
            'search' => $search,
            'locale' => app()->getLocale(),
            'app_url' => config('app.url'),
            'support_whatsapp_url' => config('services.support.whatsapp_url'),
            'support_whatsapp_number' => config('services.support.whatsapp_number'),
            'support_telegram_admin_url' => config('services.support.telegram_admin_url'),
            'support_telegram_admin_username' => config('services.support.telegram_admin_username'),
            'telegram_admin_contact_url' => config('services.telegram.admin_contact_url'),
            'telegram_admin_username' => config('services.telegram.admin_username'),
            'storefront_telegram_bot_url' => config('storefront.links.telegram_bot_url'),
            'design_version' => self::DESIGN_VERSION,
            'vite_manifest_fingerprint' => $this->viteManifestFingerprint(),
        ];

        return 'storefront.response.v'.$version.'.'.hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function viteManifestFingerprint(): string
    {
        $path = public_path('build/manifest.json');

        return File::exists($path) ? hash_file('sha256', $path) : 'missing';
    }

    private function render(string $search): string
    {
        return view('storefront', $this->catalogPages->homeViewData($search))->render();
    }

    private function deleteStaticHomepage(): void
    {
        try {
            File::delete(public_path('storefront/index.html'), public_path('storefront/index.html.tmp'));
        } catch (Throwable $exception) {
            DogfoodTiming::log('dogfood.storefront.static_delete_failed', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function log(
        int $startedAt,
        array $querySnapshot,
        string $search,
        bool $cacheHit,
        int $htmlBytes,
        ?string $note = null,
    ): void {
        DogfoodTiming::log('dogfood.storefront.response', DogfoodTiming::queryDelta($querySnapshot) + [
            'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            'cache_hit' => $cacheHit,
            'search_present' => trim($search) !== '',
            'html_bytes' => $htmlBytes,
            'note' => $note,
        ]);
    }
}
