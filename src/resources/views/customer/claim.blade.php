@extends('customer.partials.account-layout')

@section('account-title', __('customer_account.claim_page.title'))
@section('account-description', __('customer_account.claim_page.description'))

@section('account-content')
    <section class="account-panel account-claim">
        <p class="account-help">{{ __('customer_account.claim_page.help') }}</p>
        @if(session('customer_claim_error'))
            <div class="account-notice" role="alert">{{ __('customer_account.claim_page.invalid') }}</div>
        @endif
        @if($errors->any())
            <div class="account-notice" role="alert">
                <p>{{ __('customer_account.claim_page.check') }}</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('customer.claim.store') }}" class="account-form">
            @csrf
            <label class="account-label">{{ __('customer_account.claim_page.invoice') }}
                <input name="invoice_number" required value="{{ old('invoice_number') }}" class="account-input">
            </label>
            <label class="account-label">Recovery Key
                <input name="access_key" required autocomplete="off" class="account-input">
            </label>
            <button type="submit" class="account-button">{{ __('customer_account.claim') }}</button>
        </form>
        <a href="{{ route('storefront.policies.recovery') }}" class="account-text-link">{{ __('storefront_funnel.order_recovery') }}</a>
    </section>
@endsection
