<header class="sticky top-0 z-50 border-b border-[var(--sf-border)] bg-[var(--sf-surface)]" data-home-header>
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-[12px] px-[16px] py-[8px] sm:px-6" aria-label="{{ __('storefront_funnel.primary_nav') }}">
        <a href="{{ $homeUrl }}" class="home-catalog-link inline-flex min-h-[44px] items-center rounded-lg" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>
        <div class="flex flex-wrap items-center gap-x-[12px] text-sm font-semibold sm:gap-x-5">
            <a href="{{ route('storefront.guides.index') }}" class="home-catalog-link hidden min-h-[44px] items-center rounded-lg sm:inline-flex">{{ __('storefront_funnel.guides') }}</a>
            <a href="#support" class="home-catalog-link hidden min-h-[44px] items-center rounded-lg md:inline-flex">{{ __('storefront_funnel.support') }}</a>
            <a href="{{ route('storefront.order-access.create') }}" class="home-catalog-link inline-flex min-h-[44px] items-center rounded-lg">{{ __('storefront_funnel.orders') }}</a>
            <a href="{{ route('storefront.shop.cart') }}" class="home-catalog-link inline-flex min-h-[44px] items-center rounded-lg">{{ __('storefront_funnel.cart') }}</a>
            @include('storefront.partials.locale-selector')
            <x-theme-toggle class="home-catalog-link inline-flex size-[44px] min-h-[44px]! items-center justify-center rounded-lg" />
        </div>
    </nav>
</header>
