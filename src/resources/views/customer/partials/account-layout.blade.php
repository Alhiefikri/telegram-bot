<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-area @yield('account-body-class') text-[var(--sf-ink)]">
    @php($signedInAccount = isset($customer, $accountSummary))
    <div class="{{ $signedInAccount ? 'account-shell' : 'storefront-shell' }} flex min-h-screen flex-col">
        @include('storefront.partials.header')
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
