<x-storefront.panel as="section" class="invoice-delivery-panel mt-5" aria-label="{{ __('storefront_funnel.account_delivery') }}">
    @if ($isPaid && $canRevealCredentials && $accountGroups !== [])
        @php
            $mailStatus = $credentialMail['status'] ?? 'pending';
            $mailTitle = match ($mailStatus) {
                'sent' => __('storefront_funnel.mail_sent_title'),
                'failed' => __('storefront_funnel.mail_failed_title'),
                default => __('storefront_funnel.mail_pending_title'),
            };
            $mailBody = match ($mailStatus) {
                'sent' => __('storefront_funnel.mail_sent_body'),
                'failed' => __('storefront_funnel.mail_failed_body'),
                default => __('storefront_funnel.mail_pending_body'),
            };
        @endphp
        <div class="invoice-mail-status text-sm leading-5 text-[var(--sf-ink)]" data-mail-status="{{ $mailStatus }}" role="status" aria-live="polite">
            @if (($credentialMail['recipient'] ?? null) !== null)
                <div class="min-w-0">
                    <p class="invoice-mail-title font-semibold">{{ $mailTitle }}</p>
                    <p class="mt-1 break-words">{{ str_replace(':email', $credentialMail['recipient'], (string) $mailBody) }}</p>
                    @if ($mailStatus === 'sent' && filled($credentialMail['sent_at'] ?? null))
                        <p class="mt-1 text-xs text-[var(--sf-muted)]">{{ str_replace(':time', $credentialMail['sent_at'], (string) __('storefront_funnel.mail_sent_at')) }}</p>
                    @endif
                </div>
                @if ($credentialMail['can_resend'] ?? false)
                    <form method="POST" action="{{ $credentialMail['resend_url'] }}" class="invoice-mail-resend">
                        @csrf
                        <button type="submit" class="credential-action">{{ __('storefront_funnel.mail_resend_cta') }}</button>
                    </form>
                @endif
                @error('credential_mail')
                    <p role="alert" class="mt-2 font-semibold">{{ $message }}</p>
                @enderror
            @else
                <div class="min-w-0">
                    <p class="font-semibold">{{ __('storefront_funnel.mail_no_recipient_title') }}</p>
                    <p class="mt-1">{{ __('storefront_funnel.mail_no_recipient_body') }}</p>
                </div>
            @endif
        </div>
    @endif

    @if (! $isPaid)
        @if ($isTerminal)
            <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.no_account_delivery') }}</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.inactive_invoice') }}</p>
        @else
            <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.awaiting_payment') }}</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.verified_delivery_only') }}</p>
        @endif
    @elseif ($accountGroups !== [] && $canRevealCredentials)
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.account_details') }}</h2>
                <p class="mt-1 text-sm leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.hidden_credentials') }}</p>
            </div>
        </div>
        <dialog class="credential-confirm-dialog" data-credential-confirm aria-labelledby="credential-confirm-title" aria-describedby="credential-confirm-description">
            <h3 id="credential-confirm-title" class="font-display text-xl font-bold">{{ __('storefront_funnel.confirm_view_credentials') }}</h3>
            <p id="credential-confirm-description" class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.check_privacy_before_reveal') }}</p>
            <form method="dialog" class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="submit" value="cancel" class="credential-action" autofocus>{{ __('storefront_funnel.cancel') }}</button>
                <button type="submit" value="reveal" class="credential-action credential-confirm-primary">{{ __('storefront_funnel.yes_reveal_credentials') }}</button>
            </form>
        </dialog>
        <details class="mt-4" data-credential-disclosure>
            <summary class="credential-action credential-reveal-primary cursor-pointer" data-credential-disclosure-toggle data-show="{{ __('storefront_funnel.view_credentials') }}" data-hide="{{ __('storefront_funnel.hide_credentials') }}">{{ __('storefront_funnel.view_credentials') }}</summary>
            <noscript><p class="mt-3 text-sm leading-6 text-[var(--sf-ink)]">{{ __('storefront_funnel.reveal_without_js') }}</p></noscript>
            <div class="mt-3" data-copy-group>
                <button type="button" class="credential-action w-full sm:w-auto" data-credential-copy="{{ base64_encode((string) $accountCopyText) }}" data-copy-success="{{ __('storefront_funnel.all_accounts_copied') }}" hidden>{{ __('storefront_funnel.copy_all') }}</button>
                <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
            </div>
        <div class="mt-4 space-y-5">
            @foreach ($accountGroups as $group)
                @php($groupIndex = $loop->index)
                <section class="min-w-0" aria-label="{{ __('storefront_funnel.named_account_details', ['name' => $group['product_name']]) }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <h3 class="font-display text-lg font-bold text-[var(--sf-ink)]">{{ $group['product_name'] }}</h3>
                        <div data-copy-group>
                            <button type="button" class="credential-action" data-credential-copy="{{ base64_encode((string) $group['copy_text']) }}" data-copy-success="{{ __('storefront_funnel.product_details_copied', ['name' => $group['product_name']]) }}" hidden>{{ __('storefront_funnel.copy_product') }}</button>
                            <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
                        </div>
                    </div>
                    @foreach ($group['secrets'] as $secret)
                        @include('storefront.partials.shop.credential-account', ['credentialId' => 'credential-'.$groupIndex.'-'.$loop->index])
                    @endforeach
                    <div class="text-sm leading-6 text-[var(--sf-ink)]">
                        <p class="font-semibold">{{ filled($group['delivery_note']) ? __('storefront_funnel.product_instructions') : __('storefront_funnel.safe_use_instructions') }}</p>
                        <p class="mt-1 whitespace-pre-line break-words">{{ filled($group['delivery_note']) ? $group['delivery_note'] : data_get($copy, 'fulfillment.automatic_default_instruction') }}</p>
                    </div>
                    @if ($group['usage_guide'] !== null)
                        <a href="{{ $group['usage_guide']['url'] }}" class="credential-action mt-3">{{ $group['usage_guide']['title'] }}</a>
                    @endif
                </section>
            @endforeach
        </div>
        <p class="mt-4 border-t border-[var(--sf-border)] pt-4 text-sm leading-6 text-[var(--sf-ink)]">{{ __('storefront_funnel.keep_credentials_private') }}</p>
        </details>
    @elseif ($accountGroups !== [])
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.accounts_ready') }}</h2>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.reopen_with_key') }}</p>
        <a href="{{ $orderAccessUrl }}" class="credential-action mt-3">{{ __('storefront_funnel.access_order_details') }}</a>
    @elseif ($paidDeliveryType === 'credentials_unavailable')
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ data_get($copy, 'fulfillment.credentials_unavailable.title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ data_get($copy, 'fulfillment.credentials_unavailable.body') }}</p>
    @else
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.manual_delivery') }}</h2>
        <dl class="mt-3 text-sm leading-6 text-[var(--sf-ink)]">
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-semibold">{{ __('storefront_funnel.status_label') }}</dt>
                <dd>{{ $manualFulfillmentStatus === 'completed' ? __('storefront_funnel.manual_complete') : __('storefront_funnel.manual_waiting') }}</dd>
            </div>
        </dl>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">
            {{ $manualFulfillmentStatus === 'completed' ? __('storefront_funnel.manual_complete_help') : __('storefront_funnel.manual_waiting_help') }}
        </p>
    @endif

    @if ($isPaid && $accountGroups === [] && $usageGuides !== [])
        <div class="mt-4 border-t border-[var(--sf-border)] pt-4">
            <h3 class="font-display text-base font-bold text-[var(--sf-ink)]">{{ __('storefront_funnel.product_guide') }}</h3>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($usageGuides as $guide)
                    <a href="{{ $guide['url'] }}" class="credential-action">{{ $guide['title'] }}</a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($isPaid && ! auth('customer')->check())
        <div class="invoice-save-account text-sm leading-6 text-[var(--sf-muted)]">
            <p>{{ __('storefront_funnel.optional_claim') }}</p>
            <a href="{{ route('customer.account') }}" class="credential-action">{{ __('storefront_funnel.save_order_to_account') }}</a>
        </div>
    @elseif ($isPaid && ! request()->routeIs('customer.orders.show') && (string) auth('customer')->id() !== (string) $order->customer_id)
        <a href="{{ route('customer.claim.create') }}" class="credential-action mt-4">{{ __('storefront_funnel.save_order_to_account') }}</a>
    @endif
</x-storefront.panel>
