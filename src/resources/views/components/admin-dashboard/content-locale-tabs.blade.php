@props([
    'prefix',
    'label',
])

{{-- Language tabs of one localizable Product copy section. Both sections share
     the `productContentLocale` state, so switching a language in either tab strip
     switches Description and Replacement / Refund Policy together. --}}
<div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ $label }}">
    @foreach (\App\Catalog\LocalizedProductCopy::LOCALE_LABELS as $locale => $localeLabel)
        <button
            id="{{ $prefix }}-{{ $locale }}-tab"
            type="button"
            role="tab"
            data-product-content-locale="{{ $locale }}"
            aria-controls="{{ $prefix }}-{{ $locale }}-panel"
            x-bind:aria-selected="productContentLocale === @js($locale)"
            x-bind:tabindex="productContentLocale === @js($locale) ? '0' : '-1'"
            x-bind:class="productContentLocale === @js($locale) ? 'bg-admin-accent-soft text-admin-accent-strong' : 'bg-admin-surface'"
            x-on:click="setProductContentLocale(@js($locale))"
            x-on:keydown="handleProductContentTabKeydown($event)"
            class="min-h-11 rounded-lg border border-admin-line px-3 py-2 text-sm font-medium text-admin-ink focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
        >{{ $localeLabel }}</button>
    @endforeach
</div>
