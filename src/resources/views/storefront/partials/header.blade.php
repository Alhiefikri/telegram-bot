@php
    $homeUrl = $homeUrl ?? route('storefront.home');
    $shopUrl = $shopUrl ?? route('storefront.shop.index');
    $cartUrl = $cartUrl ?? route('storefront.shop.cart');
    $supportDestination = $supportDestination ?? $storefront->supportDestination();
    $headerSearch = request()->query('q');
    $headerSearch = is_string($headerSearch) ? $headerSearch : '';
    $cartCount = isset($cartItemCount) ? max(0, (int) $cartItemCount) : null;
    $cartLabel = $cartCount === null
        ? __('storefront_funnel.open_cart')
        : __('storefront_funnel.open_cart_count', ['count' => $cartCount]);
    $accountUrl = route('customer.account');
    $headerCustomer = request()->routeIs(
        'customer.account', 'customer.orders.*', 'customer.balance.show', 'customer.security', 'customer.claim.*',
    ) && ($customer ?? null) instanceof \App\Models\Customer ? $customer : null;
    $headerIdentity = $headerCustomer === null
        ? null
        : app(\App\Services\Customer\CustomerAccountOverviewReader::class)->identity($headerCustomer);
    $accountLabel = $headerIdentity['name'] ?? __('storefront_funnel.sign_up_in');
    $accountAccessibleLabel = $headerIdentity === null ? $accountLabel : __('storefront_funnel.open_profile', ['name' => $accountLabel]);
    $searchMaxLength = \App\Storefront\StorefrontCatalogSearch::MAX_LENGTH;
@endphp

<header class="storefront-header relative z-50 mx-auto mb-2 mt-2 w-[calc(100%-2rem)] max-w-7xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[0_12px_30px_rgb(18_35_63_/_0.1)] sm:mb-4 sm:mt-4" data-storefront-header data-account-identity-url="{{ route('customer.identity') }}" x-data="mobileMenu">
    <div class="relative flex min-h-16 w-full flex-wrap items-center gap-2 px-3 py-2 [--locale-panel-top:auto] sm:gap-3 sm:px-6 sm:py-3 xl:flex-nowrap">
        <a href="{{ $homeUrl }}" class="order-1 flex min-w-0 shrink-0 items-center gap-2 lg:w-[8rem] xl:w-[9rem]" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>

        <form action="{{ $shopUrl }}" method="GET" class="order-3 min-w-0 basis-full md:order-2 md:flex md:flex-1 md:basis-auto lg:min-w-64 xl:min-w-[6rem]" role="search">
            <label for="header-search" class="sr-only">{{ __('storefront_funnel.search_products') }}</label>
            <div class="storefront-search relative flex h-12 w-full items-center rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-0.5 shadow-[0_6px_18px_rgb(18_35_63_/_0.08)] sm:h-14 sm:rounded-2xl sm:p-1">
                <button type="submit" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--sf-muted)] transition-colors hover:bg-[var(--sf-surface)] hover:text-[var(--sf-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] sm:h-12" aria-label="{{ __('storefront_funnel.search_products') }}">
                    <svg class="h-5 w-5 sm:h-7 sm:w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.15a6.5 6.5 0 1 1-13 0Z" />
                    </svg>
                </button>
                <input id="header-search" type="search" name="q" value="{{ $headerSearch }}" maxlength="{{ $searchMaxLength }}" placeholder="{{ __('storefront_funnel.search_digital') }}" class="h-11 min-w-0 flex-1 border-0 bg-transparent px-1 text-base font-semibold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] shadow-none focus:outline-none focus:ring-0 sm:h-12 sm:px-2 sm:text-sm">
                <button type="submit" class="inline-flex h-11 shrink-0 items-center justify-center rounded-xl bg-[var(--sf-accent)] px-3 text-xs font-black text-white shadow-[0_4px_10px_rgb(18_35_63_/_0.12)] transition-colors hover:bg-[var(--sf-accent-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-offset-2 focus:ring-offset-[var(--sf-surface-warm)] sm:h-12 sm:px-4">
                    {{ __('storefront_funnel.search') }}
                </button>
            </div>
        </form>

        <nav class="order-4 hidden shrink-0 items-center gap-1 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-1 text-[11px] font-black text-[var(--sf-ink)] lg:order-3 lg:flex" aria-label="{{ __('storefront_funnel.primary_nav') }}">
            <a href="{{ $shopUrl }}" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.products') }}" title="{{ __('storefront_funnel.products') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.products') }}</span>
            </a>
            <a href="{{ $homeUrl }}#kategori" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.categories') }}" title="{{ __('storefront_funnel.categories') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 6 6-2 10 10-6 6L4 10V6Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 8h.01" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.categories') }}</span>
            </a>
            <a href="{{ $homeUrl }}#cara-beli" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.how_to_buy') }}" title="{{ __('storefront_funnel.how_to_buy') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 6h14M5 12h14M5 18h9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m16 16 2 2 3-3" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.how_to_buy') }}</span>
            </a>
            <a href="{{ route('storefront.order-access.create') }}" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.order_access') }}" title="{{ __('storefront_funnel.order_access') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10v18l-2-1.5L12 21l-3-1.5L7 21V3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 8h4M10 12h4" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.order_access') }}</span>
            </a>
            <a href="{{ $cartUrl }}" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ $cartLabel }}" title="{{ __('storefront_funnel.cart') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.cart') }}</span>
                @if ($cartCount !== null)
                    <span class="storefront-badge-pulse absolute -right-1 -top-1 min-w-5 rounded-full bg-[var(--sf-accent)] px-1 py-0.5 text-center text-[10px] font-black leading-4 text-white">{{ $cartCount }}</span>
                @endif
            </a>
            <a href="{{ route('storefront.guides.index') }}" class="storefront-nav-link group relative grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.guides') }}" title="{{ __('storefront_funnel.guides') }}">
                <svg class="h-7 w-7 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h7" />
                </svg>
                <span class="sr-only">{{ __('storefront_funnel.guides') }}</span>
            </a>
            <x-theme-toggle icon-class="h-7 w-7" class="h-11 w-11 shrink-0 rounded-xl text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
        </nav>

        <div class="order-2 ml-auto flex min-w-0 items-center justify-end gap-2 lg:order-4 lg:shrink-0">
            @include('storefront.partials.locale-selector')
            <span class="hidden sm:contents lg:hidden">
                <x-theme-toggle icon-class="h-7 w-7" class="h-11 w-11 shrink-0 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
            </span>
            <a href="{{ route('storefront.order-access.create') }}" class="hidden min-h-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-[var(--sf-accent)] bg-transparent px-2 text-[10px] font-black text-[var(--sf-accent)] transition-colors hover:bg-[var(--sf-accent-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] lg:inline-flex xl:px-3 xl:text-[11px]" aria-label="{{ __('storefront_funnel.order_access') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10v18l-2-1.5L12 21l-3-1.5L7 21V3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 8h4M10 12h4" />
                </svg>
                <span>{{ __('storefront_funnel.order_access_title') }}</span>
            </a>
            <a href="{{ $accountUrl }}" class="hidden min-h-11 min-w-0 max-w-[11rem] items-center justify-center gap-2 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 text-xs font-bold text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)] sm:inline-flex sm:max-w-[8rem] xl:max-w-[10rem] xl:px-3" data-account-link aria-label="{{ $accountAccessibleLabel }}" title="{{ $accountAccessibleLabel }}">
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--sf-accent-soft)] text-xs font-black [&[hidden]]:hidden" data-account-initial aria-hidden="true" @if ($headerIdentity === null) hidden @endif>{{ $headerIdentity['initial'] ?? '' }}</span>
                <span class="min-w-0 text-center @if ($headerIdentity !== null) truncate @endif" data-account-name>{{ $accountLabel }}</span>
            </a>
            <a href="{{ $cartUrl }}" class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] lg:hidden" aria-label="{{ $cartLabel }}">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" />
                </svg>
                @if ($cartCount !== null)
                    <span class="storefront-badge-pulse absolute -right-1 -top-1 min-w-5 rounded-full bg-[var(--sf-accent)] px-1 py-0.5 text-center text-[10px] font-black leading-4 text-white">{{ $cartCount }}</span>
                @endif
            </a>
            <button
                id="mobile-menu-btn"
                class="flex h-11 w-11 flex-col items-center justify-center gap-1.5 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:translate-y-0 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-offset-2 focus:ring-offset-[var(--sf-paper)] lg:hidden"
                type="button"
                aria-label="{{ __('storefront_funnel.open_navigation') }}"
                aria-controls="mobile-menu"
                aria-expanded="false"
                :aria-expanded="isOpen ? 'true' : 'false'"
                @click="toggle($event)"
            >
                <span id="menu-icon-1" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-transform duration-300" :class="{ 'rotate-45': isOpen, 'translate-y-[9px]': isOpen }"></span>
                <span id="menu-icon-2" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-transform duration-300" :class="{ 'opacity-0': isOpen }"></span>
                <span id="menu-icon-3" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-opacity duration-300" :class="{ '-rotate-45': isOpen, '-translate-y-[9px]': isOpen }"></span>
            </button>
        </div>

    </div>

    <div id="mobile-menu-backdrop" class="fixed inset-0 z-40 hidden bg-[var(--sf-ink)]/45 opacity-0 backdrop-blur-[2px] transition-opacity duration-200 lg:hidden" aria-hidden="true" :class="{ hidden: !isVisible, 'opacity-0': !isAnimating, 'opacity-100': isAnimating }" @click.self="close()"></div>

    <div id="mobile-menu" class="fixed bottom-0 left-0 top-0 z-50 hidden w-[min(84vw,22rem)] -translate-x-full overflow-y-auto border-r border-[var(--sf-border)] bg-[var(--sf-surface)] text-[var(--sf-ink)] shadow-[0_12px_30px_rgb(18_35_63_/_0.12)] transition-transform duration-300 ease-out lg:hidden" role="dialog" aria-modal="true" inert aria-hidden="true" aria-label="{{ __('storefront_funnel.store_navigation') }}" :class="{ hidden: !isVisible, '-translate-x-full': !isAnimating, 'translate-x-0': isAnimating }" :aria-hidden="isOpen ? 'false' : 'true'" :inert="!isOpen" @click="closeOnLink($event)">
        <div class="flex min-h-full flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-[var(--sf-border)] px-5 py-4">
                <a href="{{ $homeUrl }}" class="flex min-w-0 items-center gap-2" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>
                <button id="mobile-menu-close" type="button" class="grid h-10 w-10 place-items-center rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] text-xl font-black shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform active:translate-y-0" aria-label="{{ __('storefront_funnel.close_menu') }}" @click="close()">
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <nav class="flex flex-1 flex-col gap-2 px-4 py-5 text-sm font-black" aria-label="{{ __('storefront_funnel.mobile_menu') }}">
                <form action="{{ $shopUrl }}" method="GET" class="mb-2" role="search">
                    <label for="mobile-header-search" class="sr-only">{{ __('storefront_funnel.search_products') }}</label>
                    <div class="storefront-search relative">
                        <input id="mobile-header-search" type="search" name="q" value="{{ $headerSearch }}" maxlength="{{ $searchMaxLength }}" placeholder="{{ __('storefront_funnel.search_products') }}" class="neo-input h-12 w-full pl-12 pr-3 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">
                        <button type="submit" class="absolute left-1 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-md text-[var(--sf-muted)] transition-colors hover:bg-[var(--sf-accent-soft)] hover:text-[var(--sf-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" aria-label="{{ __('storefront_funnel.search_products') }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.15a6.5 6.5 0 1 1-13 0Z" />
                            </svg>
                        </button>
                    </div>
                </form>
                <a href="{{ $homeUrl }}" class="flex items-center gap-3 rounded-lg bg-[var(--sf-surface-warm)] px-4 py-3 text-[var(--sf-accent)] transition-colors hover:bg-[var(--sf-surface)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2v-9Z" /></svg>
                    </span>
                    <span>{{ __('storefront_funnel.home') }}</span>
                </a>

                <a href="{{ $shopUrl }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" /></svg>
                    </span>
                    <span>{{ __('storefront_funnel.web_catalog') }}</span>
                </a>
                <a href="{{ $homeUrl }}#kategori" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v6H4zM14 15h6v6h-6z" /></svg>
                    </span>
                    <span>{{ __('storefront_funnel.categories') }}</span>
                </a>
                <a href="{{ $cartUrl }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" /></svg>
                    </span>
                    <span>
                        {{ __('storefront_funnel.cart') }}
                        @if ($cartCount !== null)
                            ({{ $cartCount }})
                        @endif
                    </span>
                </a>

                <a href="{{ $homeUrl }}#cara-beli" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M5 12h14M5 17h9" /></svg>
                    </span>
                    <span>{{ __('storefront_funnel.how_to_buy') }}</span>
                </a>

                <a href="{{ route('storefront.guides.index') }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h7" /></svg>
                    </span>
                    <span>{{ __('storefront_funnel.guides') }}</span>
                </a>

                <a href="{{ $homeUrl }}#faq" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M9.5 9a2.5 2.5 0 1 1 4.2 1.84c-.95.74-1.7 1.3-1.7 2.66" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </span>
                    <span>FAQ</span>
                </a>

                <a href="{{ route('storefront.order-access.create') }}" class="neo-button mt-2 inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-center text-xs font-black text-white">
                    <span>{{ __('storefront_funnel.order_access_title') }}</span>
                </a>
                <a href="{{ $accountUrl }}" class="flex min-h-11 min-w-0 items-center justify-center gap-2 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-4 py-3 text-sm font-bold text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]" data-account-link aria-label="{{ $accountAccessibleLabel }}" title="{{ $accountAccessibleLabel }}">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--sf-accent-soft)] text-xs font-black [&[hidden]]:hidden" data-account-initial aria-hidden="true" @if ($headerIdentity === null) hidden @endif>{{ $headerIdentity['initial'] ?? '' }}</span>
                    <span class="min-w-0 text-center @if ($headerIdentity !== null) truncate @endif" data-account-name>{{ $accountLabel }}</span>
                </a>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-4 py-2">
                    <span class="text-xs font-black uppercase tracking-wide">{{ __('storefront_funnel.appearance') }}</span>
                    <x-theme-toggle showLabel class="min-h-10 rounded-md border border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-accent-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
                </div>
            </nav>

            <div class="border-t border-[var(--sf-border)] px-5 py-3 text-center text-[10px] font-bold text-[var(--sf-muted)]">
                {{ $brand }} · Checkout dan akses pesanan mandiri
            </div>
        </div>
    </div>

    <noscript>
        <nav class="border-t border-[var(--sf-border)] px-4 py-3 lg:hidden" aria-label="{{ __('storefront_funnel.no_script_nav') }}">
            <div class="flex flex-wrap gap-2 text-xs font-black">
                <a href="{{ $shopUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">{{ __('storefront_funnel.products') }}</a>
                <a href="{{ $homeUrl }}#kategori" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">{{ __('storefront_funnel.categories') }}</a>
                <a href="{{ $cartUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">{{ __('storefront_funnel.cart') }} @if ($cartCount !== null)({{ $cartCount }})@endif</a>
                <a href="{{ route('storefront.order-access.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">{{ __('storefront_funnel.order_access_title') }}</a>
                <a href="{{ $accountUrl }}" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2 text-[var(--sf-ink)]" aria-label="{{ $accountAccessibleLabel }}" title="{{ $accountAccessibleLabel }}">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--sf-accent-soft)] text-xs font-black [&[hidden]]:hidden" aria-hidden="true" @if ($headerIdentity === null) hidden @endif>{{ $headerIdentity['initial'] ?? '' }}</span>
                    <span class="min-w-0 text-center @if ($headerIdentity !== null) truncate @endif">{{ $accountLabel }}</span>
                </a>
                <a href="{{ route('storefront.guides.index') }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">{{ __('storefront_funnel.guides') }}</a>
                <a href="{{ $homeUrl }}#faq" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">FAQ</a>
            </div>
        </nav>
    </noscript>
</header>
