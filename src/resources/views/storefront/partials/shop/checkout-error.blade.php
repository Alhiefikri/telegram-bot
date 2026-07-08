@php
    $checkoutError = session('storefront_checkout_error') ?: $errors->first('product_id');
@endphp

@if ($checkoutError)
    <section class="mx-auto mt-6 max-w-6xl px-5 sm:px-6" aria-label="Status checkout">
        <x-storefront.notice tone="danger">
            {{ $checkoutError }}
        </x-storefront.notice>
    </section>
@endif
