<?php

namespace App\Storefront;

use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Storage;

final class StorefrontProductPlaceholder
{
    private bool $loaded = false;

    private ?string $customPath = null;

    public function imageUrl(): string
    {
        $this->load();

        if ($this->customPath !== null) {
            return str_starts_with($this->customPath, '/')
                ? $this->customPath
                : '/storage/'.ltrim($this->customPath, '/');
        }

        $configured = trim((string) config('storefront.product_placeholder', '/assets/product-placeholder.svg'));

        return str_starts_with($configured, '/') || preg_match('/^https?:\/\//i', $configured)
            ? $configured
            : '/'.ltrim($configured, '/');
    }

    public function imagePath(): ?string
    {
        $this->load();

        return $this->customPath;
    }

    public function isCustom(): bool
    {
        return $this->imagePath() !== null;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $path = StorefrontSetting::query()
            ->where('singleton_key', StorefrontSetting::SINGLETON_KEY)
            ->value('product_placeholder_image_path');
        $path = is_string($path) ? trim($path) : '';

        if ($path !== '' && Storage::disk('public')->exists($path)) {
            $this->customPath = $path;
        }
    }
}
