<?php

namespace App\Storefront;

use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StorefrontBanners
{
    public const MAX_BANNERS = 5;

    /** Aspect band the hero slide box accepts; art outside it is center-cropped. */
    private const MIN_HERO_ASPECT = 16 / 9;

    private const MAX_HERO_ASPECT = 4.0;

    private const DEFAULT_HERO_ASPECT = '4 / 1';

    public function __construct(
        private readonly ProductImageOptimizer $images,
        private readonly AuditLogger $audit,
    ) {}

    public function dashboard(): array
    {
        $setting = StorefrontSetting::current();
        $slots = collect($setting->homepage_banners ?? [])
            ->map(function (mixed $slot): mixed {
                if (! is_array($slot) || ! filled($slot['path'] ?? null)) {
                    return $slot;
                }
                $size = $this->images->dimensions($slot['path']);

                return $size === null ? $slot : $slot + $size;
            })
            ->all();

        return [
            'slots' => $slots,
            'version' => $setting->homepage_banners_version ?? 0,
            'aspect' => $this->heroAspect($slots),
            'update_url' => route('admin.storefront-banners.update'),
        ];
    }

    public function published(): array
    {
        return collect(StorefrontSetting::current()->homepage_banners ?? [])
            ->filter(fn (mixed $banner): bool => is_array($banner) && filled($banner['path'] ?? null))
            ->map(function (array $banner): array {
                $size = $this->images->dimensions($banner['path']);

                return [
                    'imageUrl' => $this->images->publicUrl($banner['path']),
                    'title' => $banner['title'],
                    'link' => $banner['link'] ?? null,
                    'width' => $size['width'] ?? null,
                    'height' => $size['height'] ?? null,
                ];
            })
            ->values()->all();
    }

    /**
     * CSS `aspect-ratio` for the hero slide box, read from the first banner whose
     * file dimensions resolve, so the uploaded art renders whole instead of being
     * cropped to a ratio the owner never chose. Kept pure: the caller passes the
     * banner entries it already resolved.
     *
     * @param  list<array<string, mixed>>  $banners
     */
    public function heroAspect(array $banners): string
    {
        foreach ($banners as $banner) {
            $width = (int) ($banner['width'] ?? 0);
            $height = (int) ($banner['height'] ?? 0);
            if ($width < 1 || $height < 1) {
                continue;
            }
            $ratio = $width / $height;
            if ($ratio < self::MIN_HERO_ASPECT) {
                return $width.' / '.max(1, (int) round($width / self::MIN_HERO_ASPECT));
            }
            if ($ratio > self::MAX_HERO_ASPECT) {
                return $width.' / '.max(1, (int) round($width / self::MAX_HERO_ASPECT));
            }

            return $width.' / '.$height;
        }

        return self::DEFAULT_HERO_ASPECT;
    }

    /** Saves fixed ordered slots; image paths always come from the database or optimizer. */
    public function save(array $slots, int $version, User $actor, ?string $ip): void
    {
        $uploaded = [];
        $removed = [];
        try {
            foreach ($slots as $index => $slot) {
                if (($slot['image'] ?? null) instanceof UploadedFile && ! ($slot['remove'] ?? false)) {
                    $uploaded[$index] = $this->images->storeStorefrontBanner($slot['image']);
                }
            }
            DB::transaction(function () use ($slots, $version, $uploaded, &$removed, $actor, $ip): void {
                $setting = StorefrontSetting::query()
                    ->where('singleton_key', StorefrontSetting::SINGLETON_KEY)
                    ->lockForUpdate()->firstOrFail();
                if ((int) $setting->homepage_banners_version !== $version) {
                    throw ValidationException::withMessages(['version' => 'Banner sudah diubah. Muat ulang halaman sebelum menyimpan.']);
                }
                $previous = $setting->homepage_banners ?? [];
                $next = [];
                foreach ($slots as $index => $slot) {
                    $oldPath = $previous[$index]['path'] ?? null;
                    $path = ($slot['remove'] ?? false) ? null : ($uploaded[$index] ?? $oldPath);
                    if ($oldPath && $path !== $oldPath) {
                        $removed[] = $oldPath;
                    }
                    $next[$index] = $path ? [
                        'path' => $path,
                        'title' => trim($slot['title'] ?? '') ?: 'Banner '.($index + 1),
                        'link' => $slot['link'] ?? null,
                    ] : null;
                }
                $setting->homepage_banners = $next;
                $setting->homepage_banners_version = $version + 1;
                $setting->save();
                $this->audit->log('storefront.banners.updated', $setting, ['homepage_banners' => $previous], ['homepage_banners' => $next], $actor, $ip);
            });
        } catch (Throwable $exception) {
            foreach ($uploaded as $path) {
                $this->images->deleteManagedImage($path);
            }
            throw $exception;
        }
        foreach ($removed as $path) {
            $this->images->deleteManagedImage($path);
        }
    }
}
