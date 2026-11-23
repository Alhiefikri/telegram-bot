<section class="account-profile-money account-panel" aria-label="{{ __('customer_account.balance') }}">
    <div class="account-profile-balances">
        @foreach(['IDR' => 'idrBalanceDisplay', 'USDT' => 'usdtBalanceDisplay'] as $unit => $key)
            <div>
                <span>{{ $unit }}</span>
                @if($accountSummary[$key] !== null)
                    <strong>{{ $accountSummary[$key] }}</strong>
                    @if($unit === 'USDT' && $accountSummary['usdtFrozen'])
                        <p class="account-note">{{ __('customer_account.frozen') }}</p>
                    @endif
                @else
                    <p class="account-balance-unavailable">{{ __('customer_account.unavailable') }}</p>
                @endif
            </div>
        @endforeach
    </div>
    <a class="account-text-link" href="{{ route('customer.balance.show') }}">{{ __('customer_account.balance_activity') }}</a>
</section>
<section class="account-order-summary account-panel" aria-labelledby="account-order-summary-title">
    <div class="account-summary-heading">
        <h2 id="account-order-summary-title">{{ __('customer_account.orders') }}</h2>
        <strong>{{ $accountSummary['orderCounts']['all'] }}</strong>
    </div>
    <dl>
        @foreach(['waiting', 'processing', 'completed', 'issue'] as $state)
            <div><dt>{{ __('customer_account.filters.'.$state) }}</dt><dd>{{ $accountSummary['orderCounts'][$state] }}</dd></div>
        @endforeach
    </dl>
    <p class="account-note">{{ __('customer_account.waiting_note') }}</p>
</section>
