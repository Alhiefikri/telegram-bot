@php
    $homeUrl = $homeUrl ?? route('storefront.home');
    $cartUrl = $cartUrl ?? route('storefront.shop.cart');
    $isHome = request()->routeIs('storefront.home');
    $cartCount = isset($cartItemCount) ? max(0, (int) $cartItemCount) : null;
    $headerCustomer = request()->routeIs(
        'customer.account', 'customer.orders.*', 'customer.balance.show', 'customer.security', 'customer.claim.*',
    ) && ($customer ?? null) instanceof \App\Models\Customer ? $customer : null;
    $headerIdentity = $headerCustomer === null
        ? null
        : app(\App\Services\Customer\CustomerAccountOverviewReader::class)->identity($headerCustomer);
    $navLinks = [
        ['href' => $homeUrl.'#kategori', 'label' => __('storefront_funnel.products')],
        ['href' => route('storefront.guides.index'), 'label' => __('storefront_funnel.guides')],
        ['href' => ($isHome ? '' : $homeUrl).'#support', 'label' => __('storefront_funnel.support')],
        ['href' => route('storefront.order-access.create'), 'label' => __('storefront_funnel.orders')],
    ];
    $cartLabel = $cartCount === null
        ? __('storefront_funnel.cart')
        : __('storefront_funnel.open_cart_count', ['count' => $cartCount]);
@endphp

<header class="storefront-header sticky top-0 z-50 border-b border-[var(--sf-border)] bg-[var(--sf-surface)]" data-storefront-header data-account-identity-url="{{ route('customer.identity') }}" x-data="mobileMenu">
    <x-storefront.frame as="nav" data-storefront-nav-frame class="flex min-h-16 items-center justify-between gap-2 py-2 lg:gap-5" aria-label="{{ __('storefront_funnel.primary_nav') }}">
        <a href="{{ $homeUrl }}" class="inline-flex min-h-11 min-w-0 shrink-0 items-center rounded-lg" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>
        <div class="flex min-w-0 items-center justify-end gap-1 text-sm font-semibold sm:gap-2 lg:gap-4">
            <div class="hidden items-center gap-4 lg:flex">
                @include('storefront.partials.navigation.links')
            </div>
            <a href="{{ $cartUrl }}" class="home-catalog-link relative inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-lg px-1.5" aria-label="{{ $cartLabel }}" @if (request()->routeIs('storefront.shop.cart')) aria-current="page" @endif>
                <svg class="size-5 lg:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" /></svg>
                <span class="hidden lg:inline">{{ __('storefront_funnel.cart') }}</span>
                @if ($cartCount !== null && $cartCount > 0)
                    <span aria-hidden="true" class="rounded-full bg-[var(--sf-accent-soft)] px-1.5 py-0.5 text-xs font-bold text-[var(--sf-accent)]">{{ $cartCount }}</span>
                @endif
            </a>
            <div class="hidden lg:block">
                @include('storefront.partials.navigation.account')
            </div>
            @include('storefront.partials.locale-selector')
            <x-theme-toggle class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg" />
            <button id="mobile-menu-btn" type="button" class="grid size-11 shrink-0 place-items-center rounded-lg border border-[var(--sf-border)] lg:hidden" aria-label="{{ __('storefront_funnel.open_navigation') }}" aria-controls="mobile-menu" aria-expanded="false" :aria-expanded="isOpen ? 'true' : 'false'" @click="toggle($event)">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
        </div>
    </x-storefront.frame>
    @include('storefront.partials.navigation.mobile-menu')
    <noscript>
        <nav class="storefront-layout-frame flex flex-wrap gap-3 pb-2 text-sm lg:hidden" aria-label="{{ __('storefront_funnel.no_script_nav') }}">
            @include('storefront.partials.navigation.links')
            @include('storefront.partials.navigation.account')
        </nav>
    </noscript>
</header>
