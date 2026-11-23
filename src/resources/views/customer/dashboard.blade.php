@extends('customer.partials.account-layout')

@section('account-eyebrow', __('customer_account.profile'))
@section('account-title', __('customer_account.overview'))
@section('account-description', __('customer_account.overview_description'))
@section('account-body-class', 'customer-overview')

@section('account-content')
    @include('customer.partials.account-summary')
    <section class="account-panel account-action-group account-shopping-group" aria-labelledby="account-shopping-title">
        <h2 id="account-shopping-title">{{ __('customer_account.shopping') }}</h2>
        <div class="account-shopping-actions">
            @foreach([
                ['customer.orders.index', 'orders', 'orders'],
                ['storefront.shop.cart', 'cart', 'cart'],
                ['customer.claim.create', 'claim', 'claim'],
            ] as [$destination, $label, $icon])
                <a class="account-shopping-action" href="{{ route($destination) }}">
                    @include('customer.partials.account-icon', ['icon' => $icon])
                    <strong>{{ __('customer_account.'.$label) }}</strong>
                </a>
            @endforeach
        </div>
    </section>
@endsection
