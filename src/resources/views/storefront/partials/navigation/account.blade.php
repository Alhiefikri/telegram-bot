@php
    $guestLabel = __('storefront_funnel.sign_up_in');
    $profileLabel = __('storefront_funnel.open_profile', ['name' => ':name']);
    $accountName = $headerIdentity['name'] ?? $guestLabel;
    $accountLabel = $headerIdentity === null ? $guestLabel : str_replace(':name', $accountName, $profileLabel);
@endphp
<a href="{{ route('customer.account') }}" class="home-catalog-link inline-flex min-h-11 min-w-0 max-w-44 items-center gap-1.5 rounded-lg px-1.5 font-semibold" data-account-link data-account-guest-label="{{ $guestLabel }}" data-account-profile-label="{{ $profileLabel }}" aria-label="{{ $accountLabel }}" title="{{ $accountLabel }}">
    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-[var(--sf-accent-soft)] text-xs font-bold [&[hidden]]:hidden" data-account-initial aria-hidden="true" @if ($headerIdentity === null) hidden @endif>{{ $headerIdentity['initial'] ?? '' }}</span>
    <span class="min-w-0 @if ($headerIdentity !== null) truncate @endif" data-account-name>{{ $accountName }}</span>
</a>
