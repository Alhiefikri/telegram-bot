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

    public function __construct(
        private readonly ProductImageOptimizer $images,
        private readonly AuditLogger $audit,
    ) {}

    public function dashboard(): array
    {
        $setting = StorefrontSetting::current();

        return [
            'slots' => $setting->homepage_banners ?? [],
            'version' => $setting->homepage_banners_version ?? 0,
            'update_url' => route('admin.storefront-banners.update'),
        ];
    }

    public function published(): array
    {
        return collect(StorefrontSetting::current()->homepage_banners ?? [])
            ->filter(fn (mixed $banner): bool => is_array($banner) && filled($banner['path'] ?? null))
            ->map(fn (array $banner): array => [
                'imageUrl' => $this->images->publicUrl($banner['path']),
                'title' => $banner['title'],
                'link' => $banner['link'] ?? null,
            ])
            ->values()->all();
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
