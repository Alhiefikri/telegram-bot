@php
    $catalogLocaleSwitcher = isset($localeCatalogReturn);
    $activeLocale = \App\Storefront\StorefrontLocale::code();
    $localeLabels = \App\Catalog\LocalizedProductCopy::LOCALE_LABELS;
    $localeReturnRoute = request()->route()?->getName();
@endphp
@if ($catalogLocaleSwitcher || in_array($localeReturnRoute, \App\Storefront\StorefrontLocaleReturn::POST_ROUTES, true))
    <details class="min-w-0 text-sm font-semibold" data-storefront-locale>
        <summary class="flex min-h-[44px] cursor-pointer list-none flex-wrap items-center gap-x-1 rounded-lg px-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">
            {{ __('storefront_locale.label') }}
            <span class="font-bold">{{ strtoupper($activeLocale) }}</span>
            <span aria-hidden="true">▾</span>
        </summary>
        {{-- Anchor to the primary nav, independently of the no-script navigation below it. --}}
        <div data-storefront-locale-panel class="absolute end-2 top-full z-[60] w-[min(16rem,calc(100%-1rem))] rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface)] p-1 shadow-lg">
            @if ($catalogLocaleSwitcher)
                @foreach ($localeLabels as $locale => $label)
                    <a href="{{ route('storefront.locale.catalog', ['locale' => $locale, 'return' => $localeCatalogReturn], absolute: false) }}" lang="{{ $locale }}" hreflang="{{ $locale }}" @if ($locale === $activeLocale) aria-current="true" @endif class="flex min-h-[44px] items-center gap-2 rounded-md px-3 wrap-anywhere hover:bg-[var(--sf-surface-warm)]"><span>{{ $label }}</span> @if ($locale === $activeLocale)<span aria-hidden="true">✓</span>@endif</a>
                @endforeach
            @else
                <form action="{{ route('storefront.locale.update', absolute: false) }}" method="POST">
                    @csrf
                    <input type="hidden" name="return" value="{{ app(\App\Storefront\StorefrontLocaleReturn::class)->path(request(), $localeReturnRoute) }}">
                    @foreach ($localeLabels as $locale => $label)
                        <button type="submit" name="locale" value="{{ $locale }}" lang="{{ $locale }}" @if ($locale === $activeLocale) aria-current="true" @endif class="flex min-h-[44px] w-full items-center gap-2 rounded-md px-3 text-left wrap-anywhere hover:bg-[var(--sf-surface-warm)]"><span>{{ $label }}</span> @if ($locale === $activeLocale)<span aria-hidden="true">✓</span>@endif</button>
                    @endforeach
                </form>
            @endif
        </div>
    </details>
@endif
