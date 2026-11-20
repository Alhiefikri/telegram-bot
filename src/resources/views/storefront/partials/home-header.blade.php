<header class="border-b border-[var(--sf-border)] bg-[var(--sf-surface)]">
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-3 px-4 py-2 sm:px-6" aria-label="{{ __('storefront_funnel.primary_nav') }}">
        <a href="{{ $homeUrl }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>
        <div class="flex flex-wrap items-center gap-x-3 text-sm font-semibold sm:gap-x-5">
            <a href="{{ route('storefront.guides.index') }}" class="home-catalog-link hidden min-h-11 items-center rounded-lg sm:inline-flex">{{ __('storefront_funnel.guides') }}</a>
            <a href="#support" class="home-catalog-link hidden min-h-11 items-center rounded-lg md:inline-flex">{{ __('storefront_funnel.support') }}</a>
            <a href="{{ route('storefront.order-access.create') }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg">{{ __('storefront_funnel.orders') }}</a>
            <a href="{{ route('storefront.shop.cart') }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg">{{ __('storefront_funnel.cart') }}</a>
            @include('storefront.partials.locale-selector')
            <x-theme-toggle class="home-catalog-link inline-flex h-11 w-11 items-center justify-center rounded-lg" />
        </div>
    </nav>
</header>
