<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-area @yield('account-body-class') text-[var(--sf-ink)]">
    @php($signedInAccount = isset($customer, $accountSummary))
    <div class="{{ $signedInAccount ? 'account-shell' : 'storefront-shell' }} flex min-h-screen flex-col">
        @if($signedInAccount)
            <header class="account-site-header">
                <a class="account-brand" href="{{ route('storefront.home') }}" aria-label="Beranda {{ $brand }}">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>
                <form class="account-site-search" action="{{ route('storefront.shop.index') }}" method="GET" role="search">
                    <label class="sr-only" for="account-catalog-search">Cari produk</label>
                    <input id="account-catalog-search" name="q" type="search" maxlength="{{ \App\Storefront\StorefrontCatalogSearch::MAX_LENGTH }}" placeholder="Cari produk">
                    <button type="submit">Cari</button>
                </form>
                <nav class="account-site-nav" aria-label="Navigasi toko">
                    <a href="{{ route('storefront.shop.index') }}">Produk</a>
                    <a class="account-cart-link" href="{{ route('storefront.shop.cart') }}">
                        @include('customer.partials.account-icon', ['icon' => 'cart'])
                        <span>
                            Keranjang
                            @if(isset($cartItemCount))
                                ({{ max(0, (int) $cartItemCount) }})
                            @endif
                        </span>
                    </a>
                    <a href="{{ route('customer.account') }}" @if(request()->routeIs('customer.account')) aria-current="page" @endif>Akun saya</a>
                    <details class="account-site-menu">
                        <summary>Menu toko</summary>
                        <div class="account-site-menu-links">
                            <a href="{{ route('storefront.home') }}#kategori">Kategori</a>
                            <a href="{{ route('storefront.home') }}#trending">Trending</a>
                            <a href="{{ route('storefront.home') }}#cara-beli">Cara beli</a>
                            <a href="{{ route('storefront.order-access.create') }}">Akses pesanan</a>
                            <a href="{{ route('storefront.guides.index') }}">Panduan</a>
                        </div>
                    </details>
                </nav>
                <x-theme-toggle icon-class="account-icon" class="account-theme-toggle account-header-theme" />
            </header>
        @else
            <div class="pt-4">@include('storefront.partials.header')</div>
        @endif
        <main class="account-main {{ $signedInAccount ? '' : 'account-main--guest' }}">
            <header class="account-page-heading">
                <h1>@yield('account-title')</h1>
                @hasSection('account-description')
                    <p>@yield('account-description')</p>
                @endif
            </header>
            <div class="account-layout {{ $signedInAccount ? '' : 'account-layout--guest' }}">
                @if($signedInAccount)
                    <aside class="account-sidebar" aria-label="Profil dan ringkasan akun">
                        @include('customer.partials.account-profile')
                    </aside>
                @endif
                <div class="account-content">
                    @if($signedInAccount && ! request()->routeIs('customer.account'))
                        @include('customer.partials.navigation')
                    @endif
                    @yield('account-content')
                    @if($signedInAccount)
                        <div class="account-session-row">
                            <p>Selesai menggunakan akun?</p>
                            <form method="POST" action="{{ route('customer.logout') }}">
                                @csrf
                                <button type="submit" class="account-logout">
                                    @include('customer.partials.account-icon', ['icon' => 'logout'])
                                    <span>Keluar</span>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </main>
        @if($signedInAccount)
            <footer class="account-site-footer">
                <p>&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
                <nav aria-label="Bantuan dan kebijakan toko">
                    <a href="{{ route('storefront.home') }}#support">Tentang / Support</a>
                    <a href="{{ route('storefront.home') }}#faq">FAQ</a>
                    <a href="{{ route('storefront.guides.index') }}">Panduan</a>
                    <a href="{{ route('storefront.policies.privacy') }}">Privasi</a>
                    <a href="{{ route('storefront.policies.terms') }}">Syarat ketentuan</a>
                    <a href="{{ route('storefront.policies.refund') }}">Refund</a>
                    <a href="{{ route('storefront.policies.recovery') }}">Pemulihan pesanan</a>
                </nav>
            </footer>
        @else
            @include('storefront.partials.footer')
        @endif
    </div>
    @yield('account-scripts')
</body>
</html>
