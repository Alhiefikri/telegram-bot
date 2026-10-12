<nav class="customer-account-nav" aria-label="Navigasi akun pelanggan">
    @foreach([
        'customer.account' => ['label' => 'Akun saya', 'icon' => 'user'],
        'customer.orders.index' => ['label' => 'Pesanan saya', 'icon' => 'orders'],
        'customer.balance.show' => ['label' => 'Saldo saya', 'icon' => 'balance'],
        'customer.claim.create' => ['label' => 'Klaim pesanan', 'icon' => 'claim'],
        'customer.security' => ['label' => 'Keamanan akun', 'icon' => 'security'],
    ] as $destination => $item)
        <a href="{{ route($destination) }}" @if(request()->routeIs($destination)) aria-current="page" @endif>
            @include('customer.partials.account-icon', ['icon' => $item['icon']])
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
