<?php

namespace App\Storefront;

use App\Models\Cart;
use App\Models\Product;
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
        private readonly StorefrontSeo $seo,
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
        $paymentOptions = $this->paymentOptions(
            $items,
            $this->productPresentations($cart, $maintenance),
            $maintenance,
        );
        $metadata = $this->seo->privatePage(
            'Keranjang Belanja | '.$this->storefront->brand(),
            'Tinjau produk digital pilihan Anda sebelum checkout di '.$this->storefront->brand().'.',
        );

        return [
            'cart' => $cart,
            'cartItems' => $items,
            'cartItemCount' => $items->sum(fn (StorefrontCartItemPresentation $item): int => $item->quantity),
            'cartSubtotal' => $cartSubtotal,
            'cartUsdtSubtotal' => $usdtSubtotal,
            'cartCurrency' => 'IDR',
            'cartHasConflicts' => $items->contains(fn (StorefrontCartItemPresentation $item): bool => $item->hasConflict()),
            'checkoutSnapshot' => $cart === null ? null : $this->guestCarts->snapshotToken($cart),
            'checkoutPaymentOptions' => $paymentOptions,
            'checkoutDefaultPaymentMethod' => $paymentOptions[0]['value'] ?? null,
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
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
            'cartUrl' => route('storefront.shop.cart'),
            'checkoutUrl' => route('storefront.shop.checkout.start'),
        ];
    }

    public function checkoutReview(Session $session): ?StorefrontCheckoutReviewPresentation
    {
        $cart = $this->guestCarts->current($session);
        if ($cart === null || $cart->cartItems->isEmpty()) {
            return null;
        }

        return $this->buildReview(new StorefrontCartCheckoutReviewSource(
            cart: $cart,
            snapshot: $this->guestCarts->snapshotToken($cart),
        ));
    }

    public function directCheckoutReview(Product $product, mixed $requestedQuantity = null): StorefrontCheckoutReviewPresentation
    {
        return $this->buildReview(new StorefrontDirectCheckoutReviewSource(
            product: $product,
            quantity: $this->normalizeQuantity($requestedQuantity, $product->availableStock()),
        ));
    }

    private function buildReview(StorefrontCheckoutReviewSource $source): StorefrontCheckoutReviewPresentation
    {
        $maintenance = $this->maintenance->current();
        $cart = $source instanceof StorefrontCartCheckoutReviewSource ? $source->cart : null;
        $directProduct = $source instanceof StorefrontDirectCheckoutReviewSource ? $source->product : null;
        $items = $directProduct === null
            ? $this->items($cart)
            : collect([$this->itemForProduct($directProduct, $source->quantity)]);
        $presentations = $directProduct === null
            ? $this->productPresentations($cart, $maintenance, false)
            : collect([$this->products->present(
                $directProduct,
                $directProduct->availableStock(),
                maintenanceState: $maintenance,
                observeExternalReadiness: false,
            )]);
        $paymentOptions = $this->paymentOptions($items, $presentations, $maintenance);
        $hasConflicts = $items->contains(fn (StorefrontCartItemPresentation $item): bool => $item->hasConflict());
        $productPresentation = $presentations->first();
        $directCanSubmit = $directProduct === null
            || ($productPresentation instanceof StorefrontProductPresentation && $productPresentation->isBuyable);
        $canSubmit = ! $maintenance->active
            && ! $hasConflicts
            && $paymentOptions !== []
            && $directCanSubmit;
        $metadata = $this->seo->privatePage(
            $directProduct === null
                ? 'Review Checkout | '.$this->storefront->brand()
                : 'Checkout '.$directProduct->name.' | '.$this->storefront->brand(),
            $directProduct === null
                ? 'Tinjau keranjang Anda sebelum membuat order di '.$this->storefront->brand().'.'
                : 'Tinjau '.$directProduct->name.' sebelum membuat order di '.$this->storefront->brand().'.',
        );
        $unavailableNotice = $this->directUnavailableNotice(
            $source,
            $items,
            $productPresentation,
            $maintenance,
        );
        $returnUrl = $directProduct === null
            ? route('storefront.shop.cart')
            : $this->urls->productUrl($directProduct);
        $checkoutUrl = $directProduct === null
            ? route('storefront.shop.checkout.start')
            : route('storefront.shop.checkout.product', ['product' => $directProduct]);
        $supportContext = $directProduct === null
            ? $this->storefront->support()->general()
            : $this->storefront->support()->prePurchase($directProduct);
        $quantityMax = $directProduct === null
            ? 20
            : max(1, min(20, $directProduct->availableStock()));

        return new StorefrontCheckoutReviewPresentation(
            source: $source,
            items: $items,
            itemCount: $items->sum(fn (StorefrontCartItemPresentation $item): int => $item->quantity),
            subtotal: $items->sum(fn (StorefrontCartItemPresentation $item): int => $item->subtotal ?? 0),
            usdtSubtotal: $items->isNotEmpty() && $items->every(fn (StorefrontCartItemPresentation $item): bool => $item->usdtUnitPrice !== null)
                ? $items->sum(fn (StorefrontCartItemPresentation $item): int => ($item->usdtUnitPrice ?? 0) * $item->quantity)
                : null,
            currency: 'IDR',
            hasConflicts: $hasConflicts,
            snapshot: $source instanceof StorefrontCartCheckoutReviewSource ? $source->snapshot : null,
            paymentOptions: $paymentOptions,
            defaultPaymentMethod: $paymentOptions[0]['value'] ?? null,
            maintenance: $maintenance,
            maintenanceNotice: StorefrontCheckoutMaintenanceCopy::notice($maintenance),
            productPresentation: $productPresentation instanceof StorefrontProductPresentation ? $productPresentation : null,
            quantityMax: $quantityMax,
            canSubmit: $canSubmit,
            unavailableNotice: $unavailableNotice,
            returnUrl: $returnUrl,
            returnLabel: $directProduct === null ? 'Kembali ke keranjang' : 'Kembali ke produk',
            metadata: $metadata,
            storefront: $this->storefront,
            brand: $this->storefront->brand(),
            copy: config('storefront'),
            homeUrl: route('storefront.home'),
            shopUrl: route('storefront.shop.index'),
            supportHref: $this->storefront->supportHref(),
            supportDestination: $this->storefront->supportDestination(),
            supportContext: $supportContext,
            cartUrl: route('storefront.shop.cart'),
            checkoutUrl: $checkoutUrl,
        );
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
            if ($product === null) {
                return new StorefrontCartItemPresentation(
                    productId: (int) $item->product_id,
                    name: 'Produk tidak ditemukan',
                    productUrl: route('storefront.shop.cart'),
                    quantity: (int) $item->quantity,
                    unitPrice: null,
                    subtotal: null,
                    usdtUnitPrice: null,
                    availableStock: null,
                    isAvailable: false,
                    conflictMessage: 'Produk ini sudah tidak tersedia. Hapus dari keranjang untuk melanjutkan.',
                );
            }

            return $this->itemForProduct($product, (int) $item->quantity);
        })->values();
    }

    private function itemForProduct(Product $product, int $quantity): StorefrontCartItemPresentation
    {
        $availableStock = $product->availableStock();
        $isPublic = $product->isPublicVisible();
        $unitPrice = (int) $product->price_amount;

        return new StorefrontCartItemPresentation(
            productId: (int) $product->id,
            name: (string) $product->name,
            productUrl: $this->urls->productUrl($product),
            quantity: $quantity,
            unitPrice: $unitPrice,
            subtotal: $unitPrice * $quantity,
            usdtUnitPrice: $product->usdt_price_amount === null ? null : (int) $product->usdt_price_amount,
            availableStock: $availableStock,
            isAvailable: $isPublic && $availableStock >= $quantity,
            conflictMessage: $this->conflictMessage($isPublic, $availableStock, $quantity),
        );
    }

    /**
     * @return Collection<int, StorefrontProductPresentation>
     */
    private function productPresentations(
        ?Cart $cart,
        CheckoutMaintenanceState $maintenance,
        bool $observeExternalReadiness = true,
    ): Collection {
        if ($cart === null) {
            return collect();
        }

        $products = $cart->cartItems->map(fn ($item) => $item->product)->filter()->values();
        if ($products->count() !== $cart->cartItems->count()) {
            return collect();
        }

        return $products->map(
            fn (Product $product): StorefrontProductPresentation => $this->products->present(
                $product,
                $product->availableStock(),
                maintenanceState: $maintenance,
                observeExternalReadiness: $observeExternalReadiness,
            ),
        );
    }

    /**
     * @param  Collection<int, StorefrontCartItemPresentation>  $items
     * @param  Collection<int, StorefrontProductPresentation>  $presentations
     * @return list<array{value:string,label:string,description:string,provider:?string}>
     */
    private function paymentOptions(
        Collection $items,
        Collection $presentations,
        CheckoutMaintenanceState $maintenance,
    ): array {
        if ($items->isEmpty() || $maintenance->active || $items->contains(
            fn (StorefrontCartItemPresentation $item): bool => $item->hasConflict(),
        ) || $presentations->count() !== $items->count()) {
            return [];
        }

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

    private function normalizeQuantity(mixed $requestedQuantity, int $availableStock): int
    {
        $maximum = min(20, max(0, $availableStock));
        if ($maximum < 1) {
            return 1;
        }

        $requested = is_scalar($requestedQuantity)
            ? filter_var((string) $requestedQuantity, FILTER_VALIDATE_INT)
            : false;

        if ($requested === false || $requested === null) {
            return 1;
        }

        return min($maximum, max(1, (int) $requested));
    }

    /**
     * @param  Collection<int, StorefrontCartItemPresentation>  $items
     */
    private function directUnavailableNotice(
        StorefrontCheckoutReviewSource $source,
        Collection $items,
        mixed $productPresentation,
        CheckoutMaintenanceState $maintenance,
    ): ?string {
        if (! $source instanceof StorefrontDirectCheckoutReviewSource) {
            return null;
        }

        $item = $items->first();
        if ($item instanceof StorefrontCartItemPresentation && $item->conflictMessage !== null) {
            return $item->conflictMessage;
        }

        if ($maintenance->active) {
            return StorefrontCheckoutMaintenanceCopy::notice($maintenance);
        }

        if ($productPresentation instanceof StorefrontProductPresentation && ! $productPresentation->isBuyable) {
            return $productPresentation->checkoutReadiness->notice;
        }

        return null;
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
