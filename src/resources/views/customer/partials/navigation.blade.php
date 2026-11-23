<nav class="customer-account-nav" aria-label="{{ __('customer_account.navigation') }}">
    @foreach([
        'shopping' => [
            ['customer.account', 'overview'],
            ['customer.balance.show', 'balance'],
            ['customer.orders.index', 'orders'],
        ],
        'settings' => [
            ['customer.profile.edit', 'edit_profile'],
            ['customer.security', 'security'],
            ['customer.profile.edit', 'preferences', '#account-preferences-heading'],
        ],
        'help_and_tools' => [
            ['customer.claim.create', 'claim'],
            ['storefront.guides.index', 'guides'],
            ['storefront.home', 'support', '#support'],
        ],
    ] as $group => $items)
        <div class="account-nav-group">
            <h2>{{ __('customer_account.'.$group) }}</h2>
            @foreach($items as $item)
                <a href="{{ route($item[0]).($item[2] ?? '') }}" @if(!isset($item[2]) && request()->routeIs($item[0])) aria-current="page" @endif>
                    <span>{{ __('customer_account.'.$item[1]) }}</span>
                    @include('customer.partials.account-icon', ['icon' => 'chevron'])
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
