@extends('customer.partials.account-layout')

@section('account-title', __('customer_account.balance'))
@section('account-description', __('customer_account.balance_page.description'))

@section('account-content')
    <section class="account-panel account-balance-panel" aria-label="{{ __('customer_account.balance_page.summary') }}">
        <div class="account-balance-currencies">
            <article>
                <h2>Store Balance · IDR</h2>
                @if($overview->storeBalance->isAvailable())
                    <p class="account-balance-value">{{ $idrBalanceDisplay }}</p>
                    <p class="account-note">{{ __('customer_account.balance_page.available') }}</p>
                @else
                    <p class="account-balance-unavailable">{{ __('customer_account.balance_page.unavailable') }}</p>
                    <p class="account-note">{{ __('customer_account.balance_page.checking') }}</p>
                @endif
            </article>
            <article>
                <h2>Customer Balance · USDT</h2>
                @if($overview->usdtBalance->isAvailable())
                    <p class="account-balance-value">{{ $usdtBalanceDisplay }}</p>
                    @if($overview->usdtBalance->isFrozen())
                        <p class="account-notice">{{ __('customer_account.balance_page.frozen') }}</p>
                    @else
                        <p class="account-note">{{ __('customer_account.balance_page.available') }}</p>
                    @endif
                @else
                    <p class="account-balance-unavailable">{{ __('customer_account.balance_page.unavailable') }}</p>
                    <p class="account-note">{{ __('customer_account.balance_page.checking') }}</p>
                @endif
            </article>
        </div>
        <p class="account-readonly-note">{{ __('customer_account.balance_page.readonly') }}</p>
    </section>

    <section class="account-panel account-history" aria-labelledby="balance-history-heading">
        <div class="account-section-heading">
            <h2 id="balance-history-heading">{{ __('customer_account.balance_activity') }}</h2>
            <nav class="account-filters" aria-label="{{ __('customer_account.balance_page.currency') }}">
                @foreach(['IDR', 'USDT'] as $currencyTab)
                    <a href="{{ route('customer.balance.show', ['currency' => $currencyTab]) }}" @if($currency === $currencyTab) aria-current="page" @endif>{{ $currencyTab }}</a>
                @endforeach
            </nav>
        </div>
        <div class="account-activity-list">
            @forelse($activityRows as $row)
                <article class="account-activity-row">
                    <div>
                        <h3>{{ $row['kind'] }}</h3>
                        <p class="account-activity-reference">{{ $row['reference'] }}</p>
                        <p class="account-note">{{ $row['state'] }}</p>
                    </div>
                    <div class="account-activity-amount">
                        <strong>{{ $row['amount'] }}</strong>
                        <time>{{ $row['occurred_at'] }}</time>
                    </div>
                </article>
            @empty
                <div class="account-empty">
                    <h3>{{ __('customer_account.balance_page.empty', ['currency' => $currency]) }}</h3>
                    <p>{{ __('customer_account.balance_page.empty_description') }}</p>
                </div>
            @endforelse
        </div>
        @if($pageLinks['newer'] || $pageLinks['older'])
            <nav class="account-history-pagination" aria-label="{{ __('customer_account.balance_page.pages') }}">
                @if($pageLinks['newer'])
                    <a href="{{ $pageLinks['newer'] }}" class="account-secondary-button">{{ __('customer_account.balance_page.newer') }}</a>
                @endif
                @if($pageLinks['older'])
                    <a href="{{ $pageLinks['older'] }}" class="account-secondary-button">{{ __('customer_account.balance_page.older') }}</a>
                @endif
            </nav>
        @endif
    </section>
@endsection
