<?php

namespace App\Storefront;

use App\Models\Product;

class StorefrontProductPresenter
{
    public function present(Product $product, ?int $stockAvailable = null): StorefrontProductPresentation
    {
        $stockAvailable ??= $product->availableStock();
        $isAvailable = $stockAvailable > 0;
        $copy = config('storefront.shop');

        return new StorefrontProductPresentation(
            id: (int) $product->id,
            categoryKey: (string) ($product->category_id ?? 'lainnya'),
            name: $product->name,
            description: (string) $product->description,
            descriptionSnippet: $this->descriptionSnippet($product),
            priceAmount: (int) $product->price_amount,
            fakeDiscountPrice: $product->fake_discount_price,
            stockAvailable: $stockAvailable,
            isAvailable: $isAvailable,
            isBuyable: $isAvailable,
            canOpenModal: $isAvailable,
            availabilityLabel: $isAvailable ? 'Tersedia' : 'Habis',
            stockLabel: $isAvailable ? 'Stok: '.$stockAvailable : 'Stok kosong',
            statusLabel: $isAvailable ? 'Siap dibeli' : 'Restock segera',
            cardActionLabel: $isAvailable ? 'Pilih Checkout' : 'Stok kosong',
            productUrl: route('storefront.products.show', $product),
            cover: new StorefrontProductCoverPresentation(
                theme: $this->coverForName($product->name),
                imageUrl: $this->publicStorageUrl($product->image_path),
            ),
            checkoutChoices: $isAvailable ? $this->checkoutChoices($product, $copy) : [],
        );
    }

    private function descriptionSnippet(Product $product): string
    {
        return collect(preg_split('/\R+/', trim((string) $product->description)))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->first() ?: 'Deskripsi produk segera dilengkapi.';
    }

    private function publicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $trackedProductImage = 'storefront/products/'.basename($path);

        return file_exists(public_path($trackedProductImage))
            ? '/'.$trackedProductImage
            : '/storage/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, string>  $copy
     * @return array<string, StorefrontProductCheckoutChoice>
     */
    private function checkoutChoices(Product $product, array $copy): array
    {
        return [
            'website_qris' => new StorefrontProductCheckoutChoice(
                key: 'website_qris',
                label: $copy['checkout_cta'] ?? 'Bayar QRIS di Website',
                description: 'Checkout langsung dari website dengan QRIS Pakasir.',
                url: route('storefront.shop.checkout'),
                method: 'POST',
                enabled: true,
                productId: (int) $product->id,
            ),
            'telegram_admin' => new StorefrontProductCheckoutChoice(
                key: 'telegram_admin',
                label: $copy['telegram_cta'] ?? 'Beli lewat Telegram',
                description: 'Pakai alur lama lewat bot/admin Telegram. Cocok kalau kamu sudah biasa order dari Telegram.',
                url: route('storefront.products.payment', $product),
                method: 'GET',
                enabled: true,
                browserAction: 'checkoutManual',
            ),
        ];
    }

    /**
     * @return array{bg:string, accent:string, badge:string, badge_bg:string, desc:string, logo:string}
     */
    private function coverForName(string $name): array
    {
        $nameLower = strtolower($name);

        $theme = [
            'bg' => 'bg-stone-900',
            'accent' => 'text-stone-100',
            'badge' => 'Sorotan',
            'badge_bg' => 'bg-stone-100 text-stone-800',
            'desc' => 'PREMIUM LICENCE',
            'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8m-4-4h8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        ];

        if (str_contains($nameLower, 'chat gpt') || str_contains($nameLower, 'chatgpt')) {
            $theme = [
                'bg' => 'bg-slate-900',
                'accent' => 'text-slate-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-slate-100 text-slate-800',
                'desc' => 'CHAT GPT PLUS',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1-4-10z" stroke-dasharray="2 2"/><path d="M12 12h.01" stroke-width="3" stroke-linecap="round"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'claude')) {
            $theme = [
                'bg' => 'bg-stone-800',
                'accent' => 'text-stone-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-stone-100 text-stone-800',
                'desc' => 'CLAUDE PRO AI',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 3v18M3 12h18" stroke-linecap="round"/><circle cx="12" cy="12" r="6" stroke-dasharray="3 3"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'canva')) {
            $theme = [
                'bg' => 'bg-slate-800',
                'accent' => 'text-slate-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-slate-100 text-slate-800',
                'desc' => 'CANVA PREMIUM',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M8 12c2-3 6-3 8 0" stroke-linecap="round"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'midjourney') || str_contains($nameLower, 'leonardo')) {
            $theme = [
                'bg' => 'bg-zinc-900',
                'accent' => 'text-zinc-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-zinc-100 text-zinc-800',
                'desc' => 'CREATIVE ART AI',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'google') || str_contains($nameLower, 'gemini')) {
            $theme = [
                'bg' => 'bg-slate-900',
                'accent' => 'text-slate-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-slate-100 text-slate-800',
                'desc' => 'GEMINI / GOOGLE',
                'logo' => '<svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2c0 5.523 4.477 10 10 10-5.523 0-10 4.477-10 10 0-5.523-4.477-10-10-10 5.523 0 10-4.477 10-10z"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'netflix')) {
            $theme = [
                'bg' => 'bg-neutral-900',
                'accent' => 'text-neutral-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-neutral-100 text-neutral-800',
                'desc' => 'NETFLIX STREAM',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M7 3v18M17 3v18M7 3l10 18" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'blackbox')) {
            $theme = [
                'bg' => 'bg-zinc-900',
                'accent' => 'text-zinc-100',
                'badge' => 'Pilihan',
                'badge_bg' => 'bg-zinc-100 text-zinc-800',
                'desc' => 'BLACKBOX AI',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z" stroke-dasharray="2 2"/></svg>',
            ];
        }

        return $theme;
    }
}
