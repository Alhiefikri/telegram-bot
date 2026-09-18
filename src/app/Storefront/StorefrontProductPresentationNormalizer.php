<?php

namespace App\Storefront;

final class StorefrontProductPresentationNormalizer
{
    public static function descriptionSnippet(string $description): string
    {
        return collect(preg_split('/\R+/', trim($description)))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->first() ?: 'Deskripsi produk segera dilengkapi.';
    }

    public static function publicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $publicPath = parse_url($path, PHP_URL_PATH);

            return is_string($publicPath)
                && $publicPath !== ''
                && ! str_starts_with($publicPath, '//')
                ? $publicPath
                : null;
        }

        $trackedProductImage = 'storefront/products/'.basename($path);

        return file_exists(public_path($trackedProductImage))
            ? '/'.$trackedProductImage
            : '/storage/'.ltrim($path, '/');
    }

    public static function configuredProductImageUrl(): string
    {
        $configured = trim((string) config('storefront.product_placeholder', '/assets/product-placeholder.svg'));

        return str_starts_with($configured, '/') || preg_match('/^https?:\/\//i', $configured)
            ? $configured
            : '/'.ltrim($configured, '/');
    }

    /**
     * @return array{bg:string, accent:string, badge:string, badge_bg:string, desc:string, logo:string}
     */
    public static function defaultCoverTheme(): array
    {
        return [
            'bg' => 'bg-stone-900',
            'accent' => 'text-stone-100',
            'badge' => 'Pilihan',
            'badge_bg' => 'bg-stone-100 text-stone-800',
            'desc' => 'PRODUK DIGITAL',
            'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8m-4-4h8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        ];
    }
}
