@extends('storefront.layouts.store')

@section('content')
        <main class="flex-grow pb-16">
            <div data-catalog-maintenance>
                @include('storefront.partials.checkout-maintenance')
            </div>
            @if ($homepageBanners !== [])
                @include('storefront.partials.hero', ['showHeroHeading' => false])
            @endif
            @include('storefront.partials.home-catalog')
            @include('storefront.partials.faq')
            @include('storefront.partials.support')
        </main>

@endsection

@section('overlays')
    @include('storefront.partials.verified-purchase-activity')
@endsection
