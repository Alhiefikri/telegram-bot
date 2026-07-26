<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden" x-data="storefrontModal()">
        <div id="scroll-progress" class="scroll-progress" aria-hidden="true"></div>
        @include('storefront.partials.header')

        <main class="flex-grow pb-32">
            @include('storefront.partials.shop.checkout-error')
            @include('storefront.partials.hero')
            @include('storefront.partials.marquee')
            @include('storefront.partials.catalog')
            @include('storefront.partials.featured-offer')
            @include('storefront.partials.benefits')
            @include('storefront.partials.purchase-flow')
            @include('storefront.partials.testimonial')
            @include('storefront.partials.metrics')
            @include('storefront.partials.faq')
            @include('storefront.partials.support')
        </main>

        @include('storefront.partials.footer')
        @include('storefront.partials.product-modal')
    </div>

</body>
</html>
