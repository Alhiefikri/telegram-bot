@extends('customer.partials.account-layout')

@section('account-eyebrow', __('customer_account.settings'))
@section('account-title', __('customer_account.edit_profile'))
@section('account-description', __('customer_account.profile_description'))

@section('account-content')
    <section class="account-panel account-settings-panel" aria-labelledby="account-personal-heading">
        <h2 id="account-personal-heading">{{ __('customer_account.personal') }}</h2>
        @if(session('customer_profile_saved'))
            <p class="account-profile-success" role="status">{{ __('customer_account.saved') }}</p>
        @endif
        <form method="POST" action="{{ route('customer.profile.update') }}" class="account-form">
            @csrf
            @method('PATCH')
            <label class="account-label" for="display-name">{{ __('customer_account.display_name') }}</label>
            @php($oldName = old('display_name', $customer->display_name))
            <input id="display-name" class="account-input" name="display_name" type="text" maxlength="80" autocomplete="nickname"
                value="{{ is_string($oldName) ? $oldName : '' }}" placeholder="{{ $customer->first_name ?: ($accountProfile['telegramUsername'] ?: __('customer_account.customer')) }}"
                aria-describedby="display-name-help{{ $errors->has('display_name') ? ' display-name-error' : '' }}" @if($errors->has('display_name')) aria-invalid="true" @endif>
            <p id="display-name-help" class="account-note">{{ __('customer_account.display_name_help') }}</p>
            @error('display_name')
                <p id="display-name-error" class="account-notice" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="account-button">{{ __('customer_account.save') }}</button>
        </form>
    </section>

    <section class="account-panel account-settings-panel" aria-labelledby="account-signin-heading">
        <h2 id="account-signin-heading">{{ __('customer_account.sign_in_details') }}</h2>
        <dl class="account-profile-details">
            <div>
                <dt>{{ __('customer_account.email') }}</dt>
                <dd>{{ $accountProfile['email'] ?? __('customer_account.no_email') }}</dd>
                @if($accountProfile['email'])
                    <dd class="account-note">{{ __('customer_account.'.($accountProfile['emailVerified'] ? 'verified' : 'unverified')) }}</dd>
                @endif
            </div>
            <div>
                <dt>Telegram</dt>
                <dd>{{ $accountProfile['telegramUsername'] ? '@'.ltrim($accountProfile['telegramUsername'], '@') : __('customer_account.'.($customer->telegram_id !== null ? 'linked' : 'not_linked')) }}</dd>
            </div>
            @if($accountProfile['joinedAt'])
                <div>
                    <dt>{{ __('customer_account.joined') }}</dt>
                    <dd>{{ $accountProfile['joinedAt'] }}</dd>
                </div>
            @endif
        </dl>
        <a class="account-text-link" href="{{ route('customer.security') }}">{{ __('customer_account.security') }}</a>
    </section>

    <section class="account-panel account-settings-panel" aria-labelledby="account-preferences-heading">
        <h2 id="account-preferences-heading">{{ __('customer_account.preferences') }}</h2>
        <div class="account-theme-row">
            <span>{{ __('customer_account.appearance') }}</span>
            <x-theme-toggle showLabel icon-class="account-icon" class="account-theme-toggle" />
        </div>
        <form class="account-form" method="POST" action="{{ route('storefront.locale.update', absolute: false) }}">
            @csrf
            <input type="hidden" name="return" value="{{ route('customer.profile.edit', absolute: false) }}">
            <label class="account-label" for="account-language">{{ __('storefront_locale.label') }}</label>
            <select class="account-input" name="locale" id="account-language">
                @foreach(\App\Catalog\LocalizedProductCopy::LOCALE_LABELS as $locale => $label)
                    <option value="{{ $locale }}" lang="{{ $locale }}" @selected($locale === \App\Storefront\StorefrontLocale::code())>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="account-secondary-button">{{ __('customer_account.save_language') }}</button>
        </form>
    </section>
@endsection
