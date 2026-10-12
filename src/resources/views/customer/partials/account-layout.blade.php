<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-area @yield('account-body-class') text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        @php($signedInAccount = isset($customer, $accountSummary))
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
                        @unless(request()->routeIs('customer.account'))
                            @include('customer.partials.navigation')
                        @endunless
                    </aside>
                @endif
                <div class="account-content">
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
        @include('storefront.partials.footer')
    </div>
    @yield('account-scripts')
</body>
</html>
