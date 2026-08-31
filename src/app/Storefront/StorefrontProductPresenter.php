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
use App\Support\PaymentProvider;

class StorefrontProductPresenter
{
    private ?bool $binancePayEnabled = null;

    private ?bool $bscRailAvailable = null;

    private ?ManualPaymentSetting $paymentSetting = null;

    private bool $paymentSettingResolved = false;

    private ?StorefrontProductCheckoutReadiness $checkoutReadiness = null;

    public function __construct(
        private readonly StorefrontSupport $support,
        private readonly CheckoutMaintenance $maintenance,
        private readonly BscReadiness $bscReadiness,
        private readonly KlikQrisCheckoutSelector $klikQrisCheckout,
        private readonly StorefrontProductPlaceholder $placeholder,
    ) {}

    public function present(Product $product, ?int $stockAvailable = null, ?int $soldQuantity = null, ?CheckoutMaintenanceState $maintenanceState = null): StorefrontProductPresentation
    {
        $stockAvailable ??= $product->availableStock();
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
        $checkoutReadiness = $this->readinessFor($paymentSetting);
        $checkoutChoices = $isAvailable && ! $maintenanceState->active
            ? $this->checkoutChoices($product, $paymentSetting, $checkoutReadiness)
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
                : ($isBuyable ? 'Pilih Checkout' : $checkoutReadiness->label));

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
            canOpenModal: $isAvailable && ($isBuyable || $maintenanceState->active),
            availabilityLabel: $isAvailable ? 'Tersedia' : 'Habis',
            stockLabel: $isAvailable ? 'Stok: '.$stockAvailable : 'Stok kosong',
            soldLabel: 'Terjual: '.$soldQuantity,
            statusLabel: $statusLabel,
            cardActionLabel: $cardActionLabel,
            productUrl: route('storefront.products.show', $product),
            cover: new StorefrontProductCoverPresentation(
                theme: StorefrontProductPresentationNormalizer::coverForName($product->name),
                imageUrl: StorefrontProductPresentationNormalizer::publicStorageUrl($product->image_path) ?? $this->defaultProductImageUrl(),
            ),
            checkoutChoices: $checkoutChoices,
            checkoutReadiness: $checkoutReadiness,
            support: $this->support->prePurchase($product),
            checkoutMaintenanceActive: $maintenanceState->active,
            checkoutMaintenanceNotice: StorefrontCheckoutMaintenanceCopy::notice($maintenanceState),
            usdtPriceAmount: $product->usdt_price_amount !== null ? (int) $product->usdt_price_amount : null,
            canJoinWaitlist: ! $isAvailable,
            waitlistUrl: route('storefront.waitlist.store', ['product' => $product->getKey()]),
            waitlistCtaLabel: (string) config('storefront.waitlist.cta', 'Beri tahu saya saat tersedia'),
            familyOptionLabel: $familyOptionLabel,
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

    private function defaultProductImageUrl(): string
    {
        return $this->placeholder->imageUrl();
    }

    /**
     * @return array<string, StorefrontProductCheckoutChoice>
     */
    private function checkoutChoices(
        Product $product,
        ?ManualPaymentSetting $setting,
        StorefrontProductCheckoutReadiness $readiness,
    ): array {
        $choices = [];

        if ($readiness->ready) {
            $isManual = $readiness->provider === PaymentProvider::MANUAL;
            $choices['website_qris'] = new StorefrontProductCheckoutChoice(
                key: 'website_qris',
                label: $isManual ? 'Bayar via QR Manual' : 'Bayar via KlikQRIS',
                description: $isManual
                    ? 'Scan QR manual pada invoice, bayar tepat sesuai total produk, lalu hubungi admin dengan bukti pembayaran dan nomor invoice ini.'
                    : 'QRIS otomatis dari KlikQRIS dibuat setelah checkout. Bayar hanya total final yang tampil di invoice.',
                url: route('storefront.shop.checkout'),
                method: 'POST',
                enabled: true,
                productId: (int) $product->id,
                provider: $readiness->provider,
            );
        }

        if ($product->usdt_price_amount === null || $setting === null) {
            return $choices;
        }

        $binancePayAvailable = $this->binancePayEnabled($setting);
        $bscAvailable = $this->bscRailAvailable($setting);

        if ($binancePayAvailable || $bscAvailable) {
            $choices['website_binance_pay'] = new StorefrontProductCheckoutChoice(
                key: 'website_binance_pay',
                label: 'Bayar dengan Binance',
                description: match (true) {
                    $binancePayAvailable && $bscAvailable => 'Pilih Binance Pay ID atau USDT BEP20 (BSC) pada langkah berikutnya.',
                    $binancePayAvailable => 'Checkout dengan Binance Pay ID pada langkah berikutnya.',
                    default => 'Checkout dengan USDT BEP20 (BSC) pada langkah berikutnya.',
                },
                url: route('storefront.shop.checkout'),
                method: 'POST',
                enabled: true,
                productId: (int) $product->id,
            );

            if ($binancePayAvailable) {
                $choices['website_binance_pay_id'] = new StorefrontProductCheckoutChoice(
                    key: 'website_binance_pay_id',
                    label: 'Pay with Binance Pay ID',
                    description: 'Send the exact USDT amount through Binance Pay ID. Follow the payment and verification instructions on the invoice.',
                    url: route('storefront.shop.checkout'),
                    method: 'POST',
                    enabled: true,
                    productId: (int) $product->id,
                );
            }

            if ($bscAvailable) {
                $choices['website_usdt_bep20'] = new StorefrontProductCheckoutChoice(
                    key: 'website_usdt_bep20',
                    label: 'Pay with USDT BEP20 (BSC)',
                    description: 'Send the exact USDT amount on BNB Smart Chain (BEP20). Submit the transaction hash after paying.',
                    url: route('storefront.shop.checkout'),
                    method: 'POST',
                    enabled: true,
                    productId: (int) $product->id,
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

    private function readinessFor(?ManualPaymentSetting $setting): StorefrontProductCheckoutReadiness
    {
        if ($this->checkoutReadiness !== null) {
            return $this->checkoutReadiness;
        }

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
                label: 'QRIS otomatis (KlikQRIS)',
                notice: 'QRIS otomatis dari KlikQRIS dibuat setelah checkout. Bayar hanya total final yang tampil di invoice.',
            );
        }

        if ($provider === PaymentProvider::KLIKQRIS) {
            return $this->checkoutReadiness = new StorefrontProductCheckoutReadiness(
                provider: $provider,
                ready: false,
                label: 'KlikQRIS belum siap',
                notice: 'QRIS otomatis KlikQRIS sedang belum siap. Coba lagi nanti atau hubungi admin.',
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

    private function bscRailAvailable(?ManualPaymentSetting $setting): bool
    {
        if ($this->bscRailAvailable !== null) {
            return $this->bscRailAvailable;
        }

        if (! (bool) $setting?->bsc_payment_enabled) {
            return $this->bscRailAvailable = false;
        }

        return $this->bscRailAvailable = $this->bscReadiness->check($setting)->isReady();
    }
}
