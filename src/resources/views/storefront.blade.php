<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        <div id="scroll-progress" class="scroll-progress" aria-hidden="true"></div>
        @include('storefront.partials.header')

        <main class="flex-grow pb-16">
            @include('storefront.partials.checkout-maintenance')
            @include('storefront.partials.shop.checkout-error')
            @include('storefront.partials.hero')
            @include('storefront.partials.catalog')
            @include('storefront.partials.verified-purchase-activity')
            @include('storefront.partials.purchase-flow')
            @include('storefront.partials.faq')
            @include('storefront.partials.support')
        </main>

        @include('storefront.partials.footer')
    </div>

</body>
</html>
