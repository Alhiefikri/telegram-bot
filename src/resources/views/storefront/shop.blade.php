<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden" x-data="storefrontModal()">
        @include('storefront.partials.header')

        <main class="flex-grow pb-24">
            @include('storefront.partials.shop.hero')
            @include('storefront.partials.shop.catalog')
        </main>

        @include('storefront.partials.footer')
        @include('storefront.partials.product-modal')
    </div>

    <div class="hidden sm:block">
        @include('storefront.partials.floating-support')
    </div>

</body>
</html>
