<nav class="customer-account-nav" aria-label="Navigasi akun pelanggan">
    @foreach(['customer.orders.index' => 'Pesanan saya', 'customer.balance.show' => 'Saldo saya', 'customer.claim.create' => 'Klaim pesanan', 'customer.security' => 'Keamanan akun'] as $destination => $label)
        <a href="{{ route($destination) }}" @if(request()->routeIs($destination)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
