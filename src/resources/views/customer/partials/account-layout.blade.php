<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-area @yield('account-body-class') text-[var(--sf-ink)]">
    @php($signedInAccount = isset($customer, $accountProfile))
    <div class="{{ $signedInAccount ? 'account-shell' : 'storefront-shell' }} flex min-h-screen flex-col">
        @include('storefront.partials.header')
        <main class="account-main {{ $signedInAccount ? '' : 'account-main--guest' }}">
            <div class="account-layout {{ $signedInAccount ? '' : 'account-layout--guest' }}">
                @if($signedInAccount)
                    <aside class="account-sidebar account-panel" aria-label="{{ __('customer_account.profile') }}">
                        <div class="account-sidebar-identity">
                            <div class="account-avatar" aria-hidden="true">{{ $accountProfile['initial'] }}</div>
                            <div class="account-identity-copy">
                                <strong>{{ $accountProfile['name'] }}</strong>
                                <p>{{ $accountProfile['email'] ?? __('customer_account.no_email') }}</p>
                            </div>
                        </div>
                        @include('customer.partials.navigation')
                        <a class="account-sidebar-catalog" href="{{ route('storefront.shop.index') }}">{{ __('customer_account.browse') }}</a>
                    </aside>
                @endif
                <div class="account-page">
                    <header class="account-page-heading">
                        <div>
                            @hasSection('account-eyebrow')
                                <span class="account-eyebrow">@yield('account-eyebrow')</span>
                            @endif
                            <h1>@yield('account-title')</h1>
                            @hasSection('account-description')
                                <p>@yield('account-description')</p>
                            @endif
                        </div>
                        @yield('account-heading-action')
                    </header>
                    <div class="account-content">
                        @yield('account-content')
                    </div>
                    @if($signedInAccount)
                        <div class="account-session-row">
                            <p>{{ __('customer_account.session_prompt') }}</p>
                            <form method="POST" action="{{ route('customer.logout') }}">
                                @csrf
                                <button type="submit" class="account-logout">
                                    @include('customer.partials.account-icon', ['icon' => 'logout'])
                                    <span>{{ __('customer_account.logout') }}</span>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </main>
        @if($signedInAccount)
            <footer class="account-site-footer">
                <p>&copy; {{ now()->year }} {{ __('storefront_funnel.copyright') }}</p>
                <nav aria-label="{{ __('customer_account.help_and_tools') }}">
                    <a href="{{ route('storefront.home') }}#support">{{ __('storefront_funnel.support') }}</a>
                    <a href="{{ route('storefront.home') }}#faq">FAQ</a>
                    <a href="{{ route('storefront.guides.index') }}">{{ __('storefront_funnel.guides') }}</a>
                    <a href="{{ route('storefront.policies.privacy') }}">{{ __('storefront_funnel.privacy_policy') }}</a>
                    <a href="{{ route('storefront.policies.terms') }}">{{ __('storefront_funnel.terms_conditions') }}</a>
                    <a href="{{ route('storefront.policies.refund') }}">{{ __('storefront_funnel.refund_policy') }}</a>
                    <a href="{{ route('storefront.policies.recovery') }}">{{ __('storefront_funnel.order_recovery') }}</a>
                </nav>
            </footer>
        @else
            @include('storefront.partials.footer')
        @endif
    </div>
    @yield('account-scripts')
</body>
</html>
