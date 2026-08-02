<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\OrderItem;
use App\Models\Product;

class StorefrontProductPresenter
{
    private ?bool $binancePayEnabled = null;

    private ?bool $binanceOrderIdAvailable = null;

    public function __construct(private readonly StorefrontSupport $support) {}

    public function present(Product $product, ?int $stockAvailable = null, ?int $soldQuantity = null): StorefrontProductPresentation
    {
        $stockAvailable ??= $product->availableStock();
        if ($soldQuantity === null) {
            $attributes = $product->getAttributes();
            $soldQuantity = array_key_exists('sold_quantity', $attributes)
                ? (int) $attributes['sold_quantity']
                : $this->soldQuantity($product);
        }
        $isAvailable = $stockAvailable > 0;
        $copy = config('storefront.shop');

        return new StorefrontProductPresentation(
            id: (int) $product->id,
            categoryKey: (string) ($product->category_id ?? 'lainnya'),
            name: $product->name,
            description: (string) $product->description,
            descriptionSnippet: $this->descriptionSnippet($product),
            priceAmount: (int) $product->price_amount,
            stockAvailable: $stockAvailable,
            soldQuantity: $soldQuantity,
            isAvailable: $isAvailable,
            isBuyable: $isAvailable,
            canOpenModal: $isAvailable,
            availabilityLabel: $isAvailable ? 'Tersedia' : 'Habis',
            stockLabel: $isAvailable ? 'Stok: '.$stockAvailable : 'Stok kosong',
            soldLabel: 'Terjual: '.$soldQuantity,
            statusLabel: $isAvailable ? 'Siap dibeli' : 'Restock segera',
            cardActionLabel: $isAvailable ? 'Pilih Checkout' : 'Stok kosong',
            productUrl: route('storefront.products.show', $product),
            cover: new StorefrontProductCoverPresentation(
                theme: $this->coverForName($product->name),
                imageUrl: $this->publicStorageUrl($product->image_path),
            ),
            checkoutChoices: $isAvailable ? $this->checkoutChoices($product, $copy) : [],
            support: $this->support->prePurchase($product),
        );
    }

    private function soldQuantity(Product $product): int
    {
        return (int) OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($order) => $order
                ->where('order_status', OrderStatus::Completed)
                ->where('payment_status', PaymentStatus::Paid))
            ->sum('quantity');
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
        $choices = [
            'website_qris' => new StorefrontProductCheckoutChoice(
                key: 'website_qris',
                label: $copy['checkout_cta'] ?? 'Bayar QRIS di Website',
                description: 'Checkout langsung dari website dengan QRIS.',
                url: route('storefront.shop.checkout'),
                method: 'POST',
                enabled: true,
                productId: (int) $product->id,
            ),
        ];

        if ($this->binancePayEnabled()
            && $product->usdt_price_amount !== null) {
            $choices['website_binance_pay'] = new StorefrontProductCheckoutChoice(
                key: 'website_binance_pay',
                label: 'Binance Pay · '.rtrim(rtrim(number_format($product->usdt_price_amount / 1_000_000, 6, '.', ''), '0'), '.').' USDT',
                description: $this->binanceOrderIdAvailable()
                    ? 'Bayar nominal USDT persis. Setelah transfer, kirim Binance Pay Order ID untuk verifikasi otomatis.'
                    : 'Bayar USDT melalui Binance Pay. Verifikasi dilakukan manual.',
                url: route('storefront.shop.checkout'),
                method: 'POST',
                enabled: true,
                productId: (int) $product->id,
            );
        }

        return $choices;
    }

    private function binancePayEnabled(): bool
    {
        if ($this->binancePayEnabled === null) {
            $setting = ManualPaymentSetting::current();
            $this->binanceOrderIdAvailable = $setting?->binance_verification_mode === 'order_id';
            $this->binancePayEnabled = (bool) $setting?->binance_pay_enabled
                && (filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid')));
        }

        return $this->binancePayEnabled;
    }

    private function binanceOrderIdAvailable(): bool
    {
        return $this->binanceOrderIdAvailable ?? false;
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
