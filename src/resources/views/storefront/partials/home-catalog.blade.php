<section id="products" data-home-catalog tabindex="-1" class="home-catalog storefront-layout-frame py-4 sm:py-6" aria-labelledby="catalog-title">
    <h1 id="catalog-title" class="mb-3 text-2xl font-black tracking-tight sm:text-3xl">{{ __('storefront_funnel.catalog') }}</h1>
    <div id="kategori" class="grid grid-cols-1 items-start gap-4 md:grid-cols-[minmax(0,13rem)_minmax(0,1fr)] lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] xl:grid-cols-[clamp(16rem,17vw,19rem)_minmax(0,1fr)] xl:gap-6 2xl:gap-8">
        <div class="catalog-family-rail hidden self-stretch md:block">
            <nav data-catalog-sidebar class="catalog-family-sidebar sticky top-20 flex flex-col" aria-labelledby="catalog-family-title">
                <h2 id="catalog-family-title" class="mb-2 shrink-0 px-1 text-base font-bold xl:mb-3 xl:text-lg">{{ __('storefront_funnel.choose_product') }}</h2>
                <ul data-catalog-family-scroll class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-1 text-base font-medium xl:gap-2.5 xl:text-lg">
                    <x-storefront.family-filter-item :href="$resetFamilyUrl" :label="__('storefront_funnel.all_products')" :selected="!$family" all />
                    @foreach ($families as $option)
                        <x-storefront.family-filter-item
                            :href="$option['url']"
                            :label="$option['name']"
                            :selected="$family?->slug === $option['slug']"
                            :image="$option['sidebarIconUrl']"
                            :icon="$option['icon']"
                        />
                    @endforeach
                </ul>
            </nav>
        </div>

        <div data-catalog-results class="min-w-0 md:min-h-72">
            <form action="{{ $homeUrl }}" method="GET" role="search" data-catalog-form class="mb-4 grid grid-cols-1 gap-2">
                <div class="md:hidden">
                    <label for="catalog-family" class="mb-1 flex items-center gap-2 text-sm font-bold">
                        @if ($family?->sidebarIconUrl)
                            <img src="{{ $family->sidebarIconUrl }}" alt="" width="24" height="24" class="size-6 rounded-md object-contain">
                        @endif
                        {{ __('storefront_funnel.choose_product') }}
                    </label>
                    <select id="catalog-family" name="family" class="home-catalog-control min-h-11 min-w-0 w-full rounded-lg px-3 py-2">
                        <option value="">{{ __('storefront_funnel.all_products') }}</option>
                        @foreach ($families as $option)
                            <option value="{{ $option['slug'] }}" @selected($family?->slug === $option['slug'])>{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="catalog-search" class="mb-1 block text-sm font-bold">{{ __('storefront_funnel.search_products') }}</label>
                    <div class="flex flex-wrap gap-2">
                        <input id="catalog-search" type="search" name="q" value="{{ $search }}" maxlength="{{ $searchMaxLength }}" placeholder="{{ __('storefront_funnel.search_placeholder') }}" class="home-catalog-control min-h-11 min-w-0 flex-1 basis-28 rounded-lg px-3 py-2">
                        <x-storefront.button type="submit" size="compact">{{ __('storefront_funnel.apply') }}</x-storefront.button>
                    </div>
                </div>
            </form>
            <p data-catalog-loading role="status" hidden class="mb-3 text-sm font-semibold text-[var(--sf-muted)]">{{ __('storefront_funnel.loading_catalog') }}</p>

            @if ($familyUnavailable)
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <p class="text-sm">{{ __('storefront_funnel.family_unavailable') }}</p>
                    <x-storefront.button :href="$resetFamilyUrl" variant="secondary" size="compact" data-catalog-link>{{ __('storefront_funnel.reset_family') }}</x-storefront.button>
                </div>
            @endif
            @if ($family || $search !== '')
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    @if ($family)
                        <x-storefront.button :href="$familyPageUrl" size="compact">
                            {{ __('storefront_funnel.details') }} {{ $family->name }} <span aria-hidden="true">→</span>
                        </x-storefront.button>
                        <x-storefront.button :href="$resetFamilyUrl" variant="secondary" size="compact" data-catalog-link>{{ __('storefront_funnel.reset_family') }}</x-storefront.button>
                    @endif
                    @if ($search !== '')
                        <x-storefront.button :href="$resetSearchUrl" variant="outline" size="compact" data-catalog-link>{{ __('storefront_funnel.clear_search') }}</x-storefront.button>
                    @endif
                </div>
            @endif

            @if ($plans->isEmpty())
                <div class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-4">
                    @if ($family || $search !== '')
                        <h2 class="font-bold">{{ __('storefront_funnel.no_matching_plans') }}</h2>
                        <x-storefront.button :href="$homeUrl" variant="secondary" size="compact" class="mt-2" data-catalog-link>{{ __('storefront_funnel.clear_filters') }}</x-storefront.button>
                    @else
                        <h2 class="font-bold">{{ __('storefront_funnel.catalog_preparing') }}</h2>
                        <p class="mt-1 text-sm">{{ __('storefront_funnel.check_stock_later') }}</p>
                    @endif
                </div>
            @else
                <div id="home-catalog-plans" class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 2xl:gap-5" role="region" aria-label="{{ __('storefront_funnel.plan_list') }}">
                    @foreach ($plans as $plan)
                        @php($product = $plan['presentation'])
                        <article id="product-{{ $product->id }}" class="storefront-card-hover home-plan-card flex min-w-0 flex-col overflow-hidden rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-sm" data-home-plan>
                            <div class="relative grid aspect-[16/10] place-items-center overflow-hidden bg-[var(--sf-surface-warm)]">
                                @if ($plan['imageUrl'])
                                    <img src="{{ $plan['imageUrl'] }}" alt="" width="320" height="200" loading="lazy" decoding="async" class="storefront-card-hover-media absolute inset-0 h-full w-full object-contain">
                                @else
                                    <span aria-hidden="true" class="storefront-card-hover-media text-4xl">{{ $product->cover->icon ?: '🛍️' }}</span>
                                @endif
                            </div>
                            <div class="flex flex-1 flex-col gap-3 border-t border-[var(--sf-border)] p-4">
                                <div class="min-w-0">
                                    @if ($plan['familyName'])
                                        <p class="wrap-anywhere text-xs font-semibold text-[var(--sf-muted)]">{{ $plan['familyName'] }}</p>
                                    @endif
                                    <h2 class="mt-1 wrap-anywhere text-base font-black leading-snug"><a href="{{ $plan['productUrl'] }}" class="home-catalog-link">{{ $plan['name'] }}</a></h2>
                                    @if ($product->merchandising->accessDuration)
                                        <p class="mt-1 wrap-anywhere text-sm text-[var(--sf-muted)]">{{ $product->merchandising->accessDuration }}</p>
                                    @endif
                                </div>
                                <div class="mt-auto">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <strong class="wrap-anywhere text-lg font-black text-[var(--sf-accent)]">{{ $storefront->idr($product->priceAmount) }}</strong>
                                        @if ($product->usdtPriceAmount > 0)
                                            <span class="wrap-anywhere text-sm font-semibold text-[var(--sf-muted)]">{{ $storefront->usdt($product->usdtPriceAmount) }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-[var(--sf-muted)]">{{ $product->stockLabel }}</p>
                                </div>
                                <div class="grid gap-2">
                                    <x-storefront.button :href="$plan['productUrl']" size="compact" :aria-label="__('storefront_funnel.view_named_details', ['name' => $plan['name']])">
                                        {{ __('storefront_funnel.view_details') }} <span aria-hidden="true">→</span>
                                    </x-storefront.button>
                                    @if ($product->isAvailable && ! $checkoutMaintenance->active)
                                        <x-storefront.button :href="$plan['checkoutUrl']" variant="secondary" size="compact">{{ __('storefront_funnel.buy_now') }}</x-storefront.button>
                                    @elseif ($product->canJoinWaitlist)
                                        <x-storefront.button :href="$plan['productUrl'].'#waitlist'" variant="outline" size="compact">{{ $product->waitlistCtaLabel }}</x-storefront.button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($previousUrl || $nextUrl)
                <nav class="mt-3 flex flex-wrap gap-4 text-sm font-semibold" aria-label="{{ __('storefront_funnel.catalog_pages') }}">
                    @if ($previousUrl)
                        <x-storefront.button :href="$previousUrl" rel="prev" variant="secondary" size="compact">{{ __('storefront_funnel.previous') }}</x-storefront.button>
                    @endif
                    @if ($nextUrl)
                        <x-storefront.button :href="$nextUrl" rel="next" variant="secondary" size="compact">{{ __('storefront_funnel.next') }}</x-storefront.button>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</section>
