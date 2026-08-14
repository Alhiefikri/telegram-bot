<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    @php
        $checkoutRestore = [
            'productId' => old('product_id'),
            'quantity' => old('quantity', 1),
            'buyerName' => old('buyer_name', ''),
            'supportContactChannel' => old('support_contact_channel', ''),
            'supportContactValue' => old('support_contact_value', ''),
            'paymentMethod' => old('payment_method'),
        ];
    @endphp

    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden"
         x-data="storefrontModal()"
         data-checkout-restore='@json($checkoutRestore, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'>
        @include('storefront.partials.header')

        <main class="flex-grow pb-24">
            @include('storefront.partials.checkout-maintenance')
            @include('storefront.partials.shop.checkout-error')
            @include('storefront.partials.shop.hero')
            @include('storefront.partials.shop.catalog')
            @include('storefront.partials.support')
        </main>

        @include('storefront.partials.footer')
        @include('storefront.partials.product-modal')
        @include('storefront.partials.verified-purchase-activity')
    </div>

</body>
</html>
