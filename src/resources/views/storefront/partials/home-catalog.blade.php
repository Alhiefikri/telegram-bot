<section id="products" class="home-catalog mx-auto max-w-6xl px-4 py-4 sm:px-6 sm:py-6" aria-labelledby="catalog-title">
    <h1 id="catalog-title" class="mb-3 text-2xl font-black tracking-tight sm:text-3xl">{{ __('storefront_funnel.catalog') }}</h1>
    <div id="kategori" class="grid grid-cols-1 items-start gap-4 md:grid-cols-[minmax(0,13rem)_minmax(0,1fr)] lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
        <nav class="hidden rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 md:block" aria-labelledby="catalog-family-title">
            <h2 id="catalog-family-title" class="mb-2 px-3 text-sm font-bold">{{ __('storefront_funnel.choose_product') }}</h2>
            <ul class="space-y-1 text-sm">
                <li>
                    <a href="{{ $resetFamilyUrl }}" @if (!$family) aria-current="true" @endif class="home-family-link flex min-h-11 items-center justify-between gap-2 rounded-lg px-3 py-2">
                        {{ __('storefront_funnel.all_products') }} @if (!$family)<span aria-hidden="true">✓</span>@endif
                    </a>
                </li>
                @foreach ($families as $option)
                    <li>
                        <a href="{{ $option['url'] }}" @if ($family?->slug === $option['slug']) aria-current="true" @endif class="home-family-link flex min-h-11 items-center justify-between gap-2 rounded-lg px-3 py-2">
                            <span class="wrap-anywhere">{{ $option['name'] }}</span>
                            @if ($family?->slug === $option['slug'])<span aria-hidden="true">✓</span>@endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0">
            <form action="{{ $homeUrl }}" method="GET" role="search" class="mb-4 grid grid-cols-1 gap-2">
                <div class="md:hidden">
                    <label for="catalog-family" class="mb-1 block text-sm font-bold">{{ __('storefront_funnel.choose_product') }}</label>
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
                        <button type="submit" class="home-catalog-primary min-h-11 rounded-lg px-4 py-2 text-sm font-bold">{{ __('storefront_funnel.apply') }}</button>
                    </div>
                </div>
            </form>

            @if ($familyUnavailable)
                <p class="mb-3 text-sm">{{ __('storefront_funnel.family_unavailable') }}<br><a href="{{ $resetFamilyUrl }}" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.reset_family') }}</a></p>
            @endif
            @if ($family || $search !== '')
                <div class="mb-2 flex flex-wrap items-center gap-x-4 text-sm">
                    @if ($family)
                        <a href="{{ $familyPageUrl }}" class="home-catalog-link inline-flex min-h-11 items-center font-bold underline">{{ __('storefront_funnel.details') }} {{ $family->name }}</a>
                        <a href="{{ $resetFamilyUrl }}" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.reset_family') }}</a>
                    @endif
                    @if ($search !== '')
                        <a href="{{ $resetSearchUrl }}" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.clear_search') }}</a>
                    @endif
                </div>
            @endif

            @if ($plans->isEmpty())
                <div class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-4">
                    @if ($family || $search !== '')
                        <h2 class="font-bold">{{ __('storefront_funnel.no_matching_plans') }}</h2>
                        <a href="{{ $homeUrl }}" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.clear_filters') }}</a>
                    @else
                        <h2 class="font-bold">{{ __('storefront_funnel.catalog_preparing') }}</h2>
                        <p class="mt-1 text-sm">{{ __('storefront_funnel.check_stock_later') }}</p>
                    @endif
                </div>
            @else
                <ul id="home-catalog-plans" class="divide-y divide-[var(--sf-border)] rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)]" aria-label="{{ __('storefront_funnel.plan_list') }}">
                    @foreach ($plans as $plan)
                        @php($product = $plan['presentation'])
                        <li id="product-{{ $product->id }}" class="grid grid-cols-1 gap-2 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,auto)] sm:items-center sm:gap-4 sm:px-4" data-home-plan>
                            <div class="flex min-w-0 items-start gap-3">
                                @if ($product->cover->icon)
                                    <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-lg bg-[var(--sf-surface-warm)]">{{ $product->cover->icon }}</span>
                                @elseif ($product->cover->imageUrl)
                                    <img src="{{ $product->cover->imageUrl }}" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-lg object-cover">
                                @endif
                                <div class="min-w-0">
                                    @if ($plan['familyName'])
                                        <p class="wrap-anywhere text-xs text-[var(--sf-muted)]">{{ $plan['familyName'] }}</p>
                                    @endif
                                    <h2 class="wrap-anywhere font-bold"><a href="{{ $plan['productUrl'] }}" class="home-catalog-link inline-flex min-h-11 items-center underline decoration-1 underline-offset-2">{{ $plan['name'] }}</a></h2>
                                    @if ($product->merchandising->accessDuration)
                                        <p class="wrap-anywhere text-sm text-[var(--sf-muted)]">{{ $product->merchandising->accessDuration }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex min-w-0 flex-wrap items-center justify-between gap-2 sm:justify-end sm:gap-4">
                                <div class="wrap-anywhere sm:text-right">
                                    <strong>{{ $storefront->idr($product->priceAmount) }}</strong>
                                    <p class="text-sm text-[var(--sf-muted)]">{{ $product->stockLabel }}</p>
                                </div>
                                @if ($product->isAvailable)
                                    <a href="{{ $plan['checkoutUrl'] }}" class="home-catalog-primary inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-bold">{{ __('storefront_funnel.buy_now') }}</a>
                                @elseif ($product->canJoinWaitlist)
                                    <a href="{{ $plan['productUrl'] }}#waitlist" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg px-1 py-2 text-sm font-semibold underline">{{ $product->waitlistCtaLabel }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($previousUrl || $nextUrl)
                <nav class="mt-3 flex flex-wrap gap-4 text-sm font-semibold" aria-label="{{ __('storefront_funnel.catalog_pages') }}">
                    @if ($previousUrl)
                        <a href="{{ $previousUrl }}" rel="prev" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.previous') }}</a>
                    @endif
                    @if ($nextUrl)
                        <a href="{{ $nextUrl }}" rel="next" class="home-catalog-link inline-flex min-h-11 items-center underline">{{ __('storefront_funnel.next') }}</a>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</section>
