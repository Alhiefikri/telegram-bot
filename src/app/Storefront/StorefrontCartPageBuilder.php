<?php

namespace App\Storefront;

use App\Models\Cart;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

final class StorefrontCartPageBuilder
{
    public function __construct(
        private readonly StorefrontGuestCart $guestCarts,
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontProductPresenter $products,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Session $session): array
    {
        $cart = $this->guestCarts->current($session);
        $maintenance = $this->maintenance->current();
        $items = $this->items($cart);
        $cartSubtotal = $items->sum(fn (StorefrontCartItemPresentation $item): int => $item->subtotal ?? 0);
        $usdtSubtotal = $items->isNotEmpty() && $items->every(fn (StorefrontCartItemPresentation $item): bool => $item->usdtUnitPrice !== null)
            ? $items->sum(fn (StorefrontCartItemPresentation $item): int => ($item->usdtUnitPrice ?? 0) * $item->quantity)
            : null;

        return [
            'cart' => $cart,
            'cartItems' => $items,
            'cartItemCount' => $items->sum(fn (StorefrontCartItemPresentation $item): int => $item->quantity),
            'cartSubtotal' => $cartSubtotal,
            'cartUsdtSubtotal' => $usdtSubtotal,
            'cartCurrency' => 'IDR',
            'cartHasConflicts' => $items->contains(fn (StorefrontCartItemPresentation $item): bool => $item->hasConflict()),
            'checkoutSnapshot' => $cart === null ? null : $this->guestCarts->snapshotToken($cart),
            'checkoutPaymentOptions' => $this->paymentOptions($items, $cart, $maintenance),
            'checkoutMaintenance' => $maintenance,
            'checkoutMaintenanceNotice' => StorefrontCheckoutMaintenanceCopy::notice($maintenance),
            'storefront' => $this->storefront,
            'brand' => $this->storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'shopUrl' => route('storefront.shop.index'),
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'supportContext' => $this->storefront->support()->general(),
            'pageTitle' => 'Keranjang Belanja | '.$this->storefront->brand(),
            'pageDescription' => 'Tinjau produk digital pilihan Anda sebelum checkout di Lhie Store.',
            'canonicalUrl' => route('storefront.shop.cart'),
            'cartUrl' => route('storefront.shop.cart'),
            'checkoutUrl' => route('storefront.shop.checkout.start'),
        ];
    }

    /**
     * @return Collection<int, StorefrontCartItemPresentation>
     */
    private function items(?Cart $cart): Collection
    {
        if ($cart === null) {
            return collect();
        }

        return $cart->cartItems->map(function ($item): StorefrontCartItemPresentation {
            $product = $item->product;
            $quantity = (int) $item->quantity;

            if ($product === null) {
                return new StorefrontCartItemPresentation(
                    productId: (int) $item->product_id,
                    name: 'Produk tidak ditemukan',
                    productUrl: route('storefront.shop.cart'),
                    quantity: $quantity,
                    unitPrice: null,
                    subtotal: null,
                    usdtUnitPrice: null,
                    availableStock: null,
                    isAvailable: false,
                    conflictMessage: 'Produk ini sudah tidak tersedia. Hapus dari keranjang untuk melanjutkan.',
                );
            }

            $availableStock = $product->availableStock();
            $isPublic = $product->isPublicVisible();
            $isAvailable = $isPublic && $availableStock >= $quantity;
            $conflictMessage = $this->conflictMessage($isPublic, $availableStock, $quantity);
            $unitPrice = (int) $product->price_amount;

            return new StorefrontCartItemPresentation(
                productId: (int) $item->product_id,
                name: (string) $product->name,
                productUrl: $this->urls->productUrl($product),
                quantity: $quantity,
                unitPrice: $unitPrice,
                subtotal: $unitPrice * $quantity,
                usdtUnitPrice: $product->usdt_price_amount === null ? null : (int) $product->usdt_price_amount,
                availableStock: $availableStock,
                isAvailable: $isAvailable,
                conflictMessage: $conflictMessage,
            );
        })->values();
    }

    /**
     * @param  Collection<int, StorefrontCartItemPresentation>  $items
     * @return list<array{value:string,label:string,description:string,provider:?string}>
     */
    private function paymentOptions(Collection $items, ?Cart $cart, CheckoutMaintenanceState $maintenance): array
    {
        if ($items->isEmpty() || $maintenance->active || $items->contains(fn (StorefrontCartItemPresentation $item): bool => $item->hasConflict())) {
            return [];
        }

        $products = $cart === null
            ? collect()
            : $cart->cartItems->map(fn ($item) => $item->product)->filter()->values();
        if ($products->count() !== $items->count()) {
            return [];
        }

        $presentations = $products->map(
            fn ($product): StorefrontProductPresentation => $this->products->present($product, $product->availableStock(), maintenanceState: $maintenance),
        );

        $definitions = [
            'qris' => 'website_qris',
            'binance_pay_id' => 'website_binance_pay_id',
            'usdt_bep20' => 'website_usdt_bep20',
        ];
        $options = [];

        foreach ($definitions as $value => $choiceKey) {
            $choice = $presentations->first()?->checkoutChoice($choiceKey);
            if ($choice === null || ! $choice->enabled || $presentations->contains(
                fn (StorefrontProductPresentation $presentation): bool => ($presentation->checkoutChoice($choiceKey)?->enabled ?? false) !== true,
            )) {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => $choice->label,
                'description' => $choice->description,
                'provider' => $choice->provider,
            ];
        }

        return $options;
    }

    private function conflictMessage(bool $isPublic, int $availableStock, int $quantity): ?string
    {
        if (! $isPublic) {
            return 'Produk ini sudah tidak tersedia untuk pembelian. Hapus dari keranjang untuk melanjutkan.';
        }

        if ($availableStock < $quantity) {
            return 'Stok produk berubah menjadi '.$availableStock.'. Kurangi jumlah atau hapus produk ini untuk melanjutkan.';
        }

        return null;
    }
}
