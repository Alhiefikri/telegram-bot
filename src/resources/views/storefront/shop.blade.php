@extends('storefront.layouts.store')

@section('content')
        @include('storefront.partials.breadcrumbs', ['metadata' => $metadata])
        <main class="flex-grow pb-16">
            @include('storefront.partials.checkout-maintenance')
            @include('storefront.partials.shop.checkout-error')
            @include('storefront.partials.hero')
            @include('storefront.partials.verified-purchase-activity')
            @include('storefront.partials.shop.catalog')
            @include('storefront.partials.support')
        </main>

@endsection
