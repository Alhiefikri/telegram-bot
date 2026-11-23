@extends('customer.partials.account-layout')

@section('account-title', __('customer_account.security'))
@section('account-body-class', 'customer-auth')
@section('account-description', __('customer_account.security_page.description'))

@section('account-content')
    <div class="account-panel account-security auth-panel">
        @include('customer.partials.auth-feedback')
        <section class="account-security-section" aria-labelledby="password-security-title">
            <h2 id="password-security-title">{{ __('customer_account.security_page.password') }}</h2>
            @if(!$authEnabled)
                <p class="auth-help mt-3">{{ __('customer_account.security_page.unavailable') }}</p>
            @elseif($customer->password === null)
                <p class="auth-help mt-3">{{ __('customer_account.security_page.enroll') }}</p>
                <form method="POST" action="{{ route('customer.password.enroll') }}" class="account-form" data-auth-form>
                    @csrf
                    <div class="account-password-fields">
                        <div>
                            <label class="auth-label">{{ __('customer_account.security_page.new_password') }}
                                <input name="password" type="password" required minlength="8" maxlength="128" autocomplete="new-password" aria-describedby="enroll-password-help" class="auth-input">
                            </label>
                            <p id="enroll-password-help" class="auth-help">{{ __('customer_account.security_page.password_help') }}</p>
                        </div>
                        <div>
                            <label class="auth-label">{{ __('customer_account.security_page.repeat_password') }}
                                <input name="password_confirmation" type="password" required minlength="8" maxlength="128" autocomplete="new-password" class="auth-input">
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="account-button">{{ __('customer_account.security_page.save_password') }}</button>
                    <p data-auth-progress role="status" class="auth-help" hidden>{{ __('customer_account.security_page.progress') }}</p>
                </form>
            @else
                <p class="auth-help mt-3">{{ __('customer_account.security_page.existing_password') }}</p>
            @endif
        </section>
        @if($authEnabled)
            <section class="account-security-section" aria-labelledby="google-security-title">
                <h2 id="google-security-title">Google</h2>
                <p class="auth-help mt-2">{{ __('customer_account.security_page.google_help') }}</p>
                @if($googleLinked)
                    <p class="mt-3 text-sm font-bold">{{ __('customer_account.security_page.google_linked') }}</p>
                    @if($googleReady)
                        <form method="POST" action="{{ route('customer.google.unlink') }}" class="mt-3" data-auth-form>
                            @csrf
                            <button type="submit" class="account-secondary-button">{{ __('customer_account.security_page.google_unlink') }}</button>
                            <p data-auth-progress role="status" class="auth-help" hidden>{{ __('customer_account.security_page.google_unlink_progress') }}</p>
                        </form>
                    @else
                        <p class="auth-help mt-3">{{ __('customer_account.security_page.google_unlink_unavailable') }}</p>
                    @endif
                    <p class="auth-help mt-2">{{ __('customer_account.security_page.google_unlink_help') }}</p>
                @elseif($googleReady)
                    <form method="POST" action="{{ route('customer.google.link') }}" class="mt-3" data-auth-form>
                        @csrf
                        <button type="submit" class="account-secondary-button">{{ __('customer_account.security_page.google_link') }}</button>
                        <p data-auth-progress role="status" class="auth-help" hidden>{{ __('customer_account.security_page.google_link_progress') }}</p>
                    </form>
                @else
                    <p class="auth-help mt-3">{{ __('customer_account.security_page.google_unavailable') }}</p>
                @endif
            </section>
            <section class="account-security-section" aria-labelledby="telegram-security-title">
                <h2 id="telegram-security-title">Telegram</h2>
                @if($customer->telegram_id !== null)
                    <p class="auth-help mt-2">{{ __('customer_account.security_page.telegram_linked') }}</p>
                    @if($telegramAuthenticated && $customer->email_verified_at === null)
                        <form method="POST" action="{{ route('customer.telegram.email') }}" class="account-form" data-auth-form>
                            @csrf
                            <label class="auth-label">{{ __('customer_account.security_page.email_verification') }}
                                <input class="auth-input" name="email" type="email" required maxlength="254" autocomplete="email">
                            </label>
                            <p class="auth-help">{{ __('customer_account.security_page.email_help') }}</p>
                            <button class="account-button" type="submit">{{ __('customer_account.security_page.email_send') }}</button>
                            <p data-auth-progress role="status" class="auth-help" hidden>{{ __('customer_account.security_page.progress') }}</p>
                        </form>
                    @endif
                @elseif($telegramReady)
                    <p class="auth-help mt-2">{{ __('customer_account.security_page.telegram_help') }}</p>
                    <form method="POST" action="{{ route('customer.telegram.link') }}" data-telegram-form data-status-url="{{ route('customer.telegram.status') }}" data-consume-url="{{ route('customer.telegram.consume') }}" data-orders-url="{{ route('customer.security') }}" hidden>
                        @csrf
                        <button type="submit" class="account-secondary-button">{{ __('customer_account.security_page.telegram_link') }}</button>
                    </form>
                    <div data-telegram-status role="status" class="auth-help mt-2"></div>
                    <noscript><p class="auth-help">{{ __('customer_account.security_page.telegram_no_js') }}</p></noscript>
                @else
                    <p class="auth-help mt-2">{{ __('customer_account.security_page.telegram_unavailable') }}</p>
                @endif
            </section>
        @endif
        <a href="{{ route('customer.orders.index') }}" class="account-text-link">{{ __('customer_account.security_page.back') }}</a>
    </div>
@endsection

@section('account-scripts')
    @include('customer.partials.auth-progress')
@endsection
