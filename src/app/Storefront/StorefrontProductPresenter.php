<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Bsc\BscReadiness;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\KlikQris\KlikQrisCheckoutSelector;
use App\Services\Pricing\ProductPriceTierQuote;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Support\PaymentProvider;

class StorefrontProductPresenter
{
    private ?bool $binancePayEnabled = null;

    private ?bool $bscRailAvailable = null;

    private ?ManualPaymentSetting $paymentSetting = null;

    private bool $paymentSettingResolved = false;

    private ?StorefrontProductCheckoutReadiness $checkoutReadiness = null;

    private ?bool $checkoutReadinessObservedExternally = null;

    private ?bool $bscRailReadinessObservedExternally = null;

    public function __construct(
        private readonly CheckoutMaintenance $maintenance,
        private readonly BscReadiness $bscReadiness,
        private readonly KlikQrisCheckoutSelector $klikQrisCheckout,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly StorefrontProductPlaceholder $placeholder,
        private readonly ProductQuantityPricing $pricing,
    ) {}

    public function present(
        Product $product,
        ?int $stockAvailable = null,
        ?int $soldQuantity = null,
        ?CheckoutMaintenanceState $maintenanceState = null,
        bool $observeExternalReadiness = true,
    ): StorefrontProductPresentation {
        $stockAvailable ??= $product->availableStock();
        $pricingQuote = $stockAvailable > 0 ? $this->pricing->quote($product, 1) : null;
        $reachableStock = min(
            max(0, $stockAvailable),
            ProductQuantityPricing::MAXIMUM_TIER_QUANTITY,
        );
        $reachableQuantityPriceTiers = $reachableStock < 1
            ? []
            : array_values(array_filter(
                $pricingQuote?->tiers() ?? [],
                static fn (ProductPriceTierQuote $tier): bool => $tier->minimumQuantity <= $reachableStock,
            ));
        $maintenanceState ??= $this->maintenance->current();
        if ($soldQuantity === null) {
            $attributes = $product->getAttributes();
            $soldQuantity = array_key_exists('sold_quantity', $attributes)
                ? (int) $attributes['sold_quantity']
                : $this->soldQuantity($product);
        }
        $familyOptionLabel = trim((string) $product->family_option_label);
        $familyOptionLabel = $familyOptionLabel === '' ? null : $familyOptionLabel;

        $isAvailable = $stockAvailable > 0;
        $paymentSetting = $this->paymentSetting();
        $checkoutReadiness = $this->readinessFor($paymentSetting, $observeExternalReadiness);
        $checkoutChoices = $isAvailable && ! $maintenanceState->active
            ? $this->checkoutChoices($product, $paymentSetting, $checkoutReadiness, $observeExternalReadiness)
            : [];
        $isBuyable = $isAvailable && ! $maintenanceState->active && $checkoutChoices !== [];
        $statusLabel = ! $isAvailable
            ? 'Restock segera'
            : ($maintenanceState->active
                ? 'Checkout maintenance'
                : ($isBuyable ? 'Siap dibeli' : $checkoutReadiness->label));
        $cardActionLabel = ! $isAvailable
            ? 'Stok kosong'
            : ($maintenanceState->active
                ? 'Checkout maintenance'
                : ($isBuyable ? 'Beli sekarang' : $checkoutReadiness->label));

        return new StorefrontProductPresentation(
            id: (int) $product->id,
            categoryKey: (string) ($product->category_id ?? 'lainnya'),
            name: $product->name,
            description: (string) $product->description,
            descriptionSnippet: StorefrontProductPresentationNormalizer::descriptionSnippet((string) $product->description),
            priceAmount: (int) $product->price_amount,
            merchandising: StorefrontProductMerchandising::fromProduct($product),
            stockAvailable: $stockAvailable,
            soldQuantity: $soldQuantity,
            isAvailable: $isAvailable,
            isBuyable: $isBuyable,
            availabilityLabel: $isAvailable ? 'Tersedia' : 'Habis',
            stockLabel: $isAvailable ? 'Stok: '.$stockAvailable : 'Stok kosong',
            soldLabel: 'Terjual: '.$soldQuantity,
            statusLabel: $statusLabel,
            cardActionLabel: $cardActionLabel,
            productUrl: route('storefront.products.show', $product),
            cover: new StorefrontProductCoverPresentation(
                theme: StorefrontProductPresentationNormalizer::defaultCoverTheme(),
                imageUrl: $this->imageUrl($product),
                icon: $product->icon,
            ),
            checkoutChoices: $checkoutChoices,
            checkoutReadiness: $checkoutReadiness,
            checkoutMaintenanceActive: $maintenanceState->active,
            checkoutMaintenanceNotice: StorefrontCheckoutMaintenanceCopy::notice($maintenanceState),
            usdtPriceAmount: $product->usdt_price_amount !== null ? (int) $product->usdt_price_amount : null,
            canJoinWaitlist: ! $isAvailable,
            waitlistUrl: route('storefront.waitlist.store', ['product' => $product->getKey()]),
            waitlistCtaLabel: (string) config('storefront.waitlist.cta', 'Beri tahu saya saat tersedia'),
            familyOptionLabel: $familyOptionLabel,
            reachableQuantityPriceTiers: $reachableQuantityPriceTiers,
        );
    }

    private function soldQuantity(Product $product): int
    {
        return (int) OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($order) => $order
                ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
                ->where('payment_status', PaymentStatus::Paid))
            ->sum('quantity');
    }

    private function imageUrl(Product $product): ?string
    {
        $imageUrl = StorefrontProductPresentationNormalizer::publicStorageUrl($product->image_path);

        return $imageUrl ?? $this->defaultProductImageUrl($product->icon);
    }

    private function defaultProductImageUrl(?string $icon = null): ?string
    {
        return trim((string) $icon) === '' ? $this->placeholder->imageUrl() : null;
    }

    /**
     * @return array<string, StorefrontProductCheckoutChoice>
     */
    private function checkoutChoices(
        Product $product,
        ?ManualPaymentSetting $setting,
        StorefrontProductCheckoutReadiness $readiness,
        bool $observeExternalReadiness,
    ): array {
        $choices = [];

        if ($readiness->ready) {
            $isManual = $readiness->provider === PaymentProvider::MANUAL;
            $choices['website_qris'] = new StorefrontProductCheckoutChoice(
                key: 'website_qris',
                label: $isManual ? 'Bayar via QR Manual' : 'Bayar via QRIS',
                description: $isManual
                    ? 'Scan QR manual pada invoice, bayar tepat sesuai total produk, lalu hubungi admin dengan bukti pembayaran dan nomor invoice ini.'
                    : 'QRIS otomatis dibuat setelah checkout. Bayar hanya total final yang tampil di invoice.',
                enabled: true,
                provider: $readiness->provider,
            );
        }

        if ($product->usdt_price_amount === null || $setting === null) {
            return $choices;
        }

        $binancePayAvailable = $this->binancePayEnabled($setting);
        $bscAvailable = $this->bscRailAvailable($setting, $observeExternalReadiness);

        if ($binancePayAvailable || $bscAvailable) {
            $choices['website_binance_pay'] = new StorefrontProductCheckoutChoice(
                key: 'website_binance_pay',
                label: 'Bayar dengan USDT',
                description: match (true) {
                    $binancePayAvailable && $bscAvailable => 'Pilih metode pembayaran USDT: Binance Pay atau USDT BSC BEP20.',
                    $binancePayAvailable => 'Checkout dengan Binance Pay.',
                    default => 'Checkout dengan USDT BSC BEP20.',
                },
                enabled: true,
            );

            if ($binancePayAvailable) {
                $choices['website_binance_pay_id'] = new StorefrontProductCheckoutChoice(
                    key: 'website_binance_pay_id',
                    label: 'Bayar dengan Binance Pay',
                    description: 'Kirim jumlah USDT persis melalui Binance Pay ID. Ikuti instruksi pembayaran pada invoice.',
                    enabled: true,
                );
            }

            if ($bscAvailable) {
                $choices['website_usdt_bep20'] = new StorefrontProductCheckoutChoice(
                    key: 'website_usdt_bep20',
                    label: 'Bayar dengan USDT BSC BEP20',
                    description: 'Kirim jumlah USDT persis melalui BNB Smart Chain (BEP20). Masukkan TX hash setelah membayar.',
                    enabled: true,
                );
            }
        }

        return $choices;
    }

    private function paymentSetting(): ?ManualPaymentSetting
    {
        if (! $this->paymentSettingResolved) {
            $this->paymentSetting = ManualPaymentSetting::current();
            $this->paymentSettingResolved = true;
        }

        return $this->paymentSetting;
    }

    private function readinessFor(?ManualPaymentSetting $setting, bool $observeExternalReadiness): StorefrontProductCheckoutReadiness
    {
        if ($this->checkoutReadiness !== null
            && $this->checkoutReadinessObservedExternally === $observeExternalReadiness
        ) {
            return $this->checkoutReadiness;
        }

        $this->checkoutReadinessObservedExternally = $observeExternalReadiness;

        if ($setting === null) {
            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: null,
                ready: false,
                label: 'Checkout belum tersedia',
                notice: 'Checkout pembayaran belum aktif. Hubungi admin atau coba lagi nanti.',
            );
        }

        $provider = $setting->paymentProvider();
        if ($provider === PaymentProvider::MANUAL) {
            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: $provider,
                ready: true,
                label: 'QR Manual',
                notice: 'Pembayaran manual: scan QR pada invoice, bayar tepat sesuai total produk, lalu hubungi admin dengan bukti pembayaran dan nomor invoice ini.',
            );
        }

        if ($provider === PaymentProvider::KLIKQRIS && $this->klikQrisCheckout->enabled()) {
            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: $provider,
                ready: true,
                label: 'QRIS otomatis',
                notice: 'QRIS otomatis dibuat setelah checkout. Bayar hanya total final yang tampil di invoice.',
            );
        }

        if ($provider === PaymentProvider::KLIKQRIS) {
            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: $provider,
                ready: false,
                label: 'QRIS otomatis belum siap',
                notice: 'QRIS otomatis sedang belum siap. Coba lagi nanti atau hubungi admin.',
            );
        }

        if ($provider === PaymentProvider::SAKURUPIAH) {
            $readiness = $observeExternalReadiness
                ? $this->sakurupiahReadiness->observe()
                : $this->sakurupiahReadiness->configurationReport();

            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: $provider,
                ready: $readiness->isReady(),
                label: $readiness->isReady() ? 'QRIS otomatis' : 'QRIS otomatis belum siap',
                notice: $readiness->isReady()
                    ? 'QRIS otomatis dibuat setelah checkout. Bayar hanya total final yang tampil di invoice.'
                    : 'QRIS otomatis sedang belum siap. Coba lagi nanti atau hubungi admin.',
            );
        }

        return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
            provider: $provider,
            ready: false,
            label: 'Checkout belum tersedia',
            notice: 'Provider pembayaran ini belum tersedia untuk checkout baru. Coba lagi nanti atau hubungi admin.',
        );
    }

    private function binancePayEnabled(?ManualPaymentSetting $setting): bool
    {
        if ($this->binancePayEnabled === null) {
            $this->binancePayEnabled = (bool) $setting?->binance_pay_enabled
                && (filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid')));
        }

        return $this->binancePayEnabled;
    }

    private function bscRailAvailable(?ManualPaymentSetting $setting, bool $observeExternalReadiness): bool
    {
        if ($this->bscRailAvailable !== null
            && $this->bscRailReadinessObservedExternally === $observeExternalReadiness
        ) {
            return $this->bscRailAvailable;
        }

        $this->bscRailReadinessObservedExternally = $observeExternalReadiness;
        $this->bscRailAvailable = (bool) $setting?->bsc_payment_enabled
            && ($observeExternalReadiness
                ? $this->bscReadiness->check($setting)->isReady()
                : $this->bscReadiness->configuration($setting)->isReady());

        return $this->bscRailAvailable;
    }
}
