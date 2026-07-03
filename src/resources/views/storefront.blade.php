@php
    use App\Storefront\StorefrontPresenter;

    /** @var StorefrontPresenter $storefront */
    $storefront = app(StorefrontPresenter::class);
    $brand = $storefront->brand();
    $copy = config('storefront');
    $pageTitle = str_replace(':brand', $brand, $copy['meta']['title']);
    $pageDescription = $copy['meta']['description'];
    $canonicalUrl = url('/');
    $supportHref = $storefront->supportHref();
    $supportDestination = $storefront->supportDestination();
    $categoriesWithProducts = $storefront->categoriesWithProducts($categories, $products);
    $catalogProducts = $products;
    $popularProducts = $storefront->popularProducts($products);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-32">
            @include('storefront.partials.hero')
            @include('storefront.partials.trust-marquee')
            @include('storefront.partials.featured-offer')
            @include('storefront.partials.catalog')
            @include('storefront.partials.benefits')
            @include('storefront.partials.purchase-flow')
            @include('storefront.partials.testimonial')
            @include('storefront.partials.metrics')
            @include('storefront.partials.faq')
            @include('storefront.partials.support')
        </main>

        @include('storefront.partials.footer')
    </div>

    @include('storefront.partials.floating-support')
</body>
</html>
