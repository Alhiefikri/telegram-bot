<div data-catalog-fragment data-robots="{{ $metadata->robots }}" data-locale="{{ $metadata->htmlLocale() }}">
    @include('storefront.partials.locale-selector')
    <div data-catalog-maintenance>
        @include('storefront.partials.checkout-maintenance')
    </div>
    @include('storefront.partials.home-catalog')
</div>
