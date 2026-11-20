<div class="min-w-0" data-copy-group @if($sensitive) data-credential-secret @endif>
    <p id="{{ $valueId }}-label" class="text-sm font-semibold text-[var(--sf-ink)]">{{ $valueLabel }}</p>
    @if ($sensitive)
        <p class="mt-2 text-sm text-[var(--sf-muted)]" data-secret-mask hidden>{{ __('storefront_funnel.hidden') }}</p>
    @endif
    <pre id="{{ $valueId }}" class="credential-value mt-2 max-w-full whitespace-pre-wrap break-all font-mono text-sm leading-6 text-[var(--sf-ink)]" aria-labelledby="{{ $valueId }}-label" @if($sensitive) data-secret-value hidden tabindex="0" @endif>@if(! $sensitive){{ $value }}@endif</pre>
    @if ($sensitive)
        <noscript>
            <details class="mt-2">
                <summary class="credential-action cursor-pointer" aria-label="{{ __('storefront_funnel.show_credential', ['label' => $valueLabel.' '.$accountLabel]) }}">{{ __('storefront_funnel.show_hide') }}</summary>
                <pre class="credential-value mt-2 max-w-full whitespace-pre-wrap break-all font-mono text-sm leading-6 text-[var(--sf-ink)]" tabindex="0"><span>{{ $value }}</span></pre>
            </details>
        </noscript>
    @endif
    <div class="mt-2 flex flex-wrap gap-2">
        @if ($sensitive)
            <button type="button" class="credential-action" data-secret-toggle data-show="{{ __('storefront_funnel.show') }}" data-hide="{{ __('storefront_funnel.hide') }}" data-show-label="{{ __('storefront_funnel.show_credential', ['label' => $valueLabel.' '.$accountLabel]) }}" data-hide-label="{{ __('storefront_funnel.hide_credential', ['label' => $valueLabel.' '.$accountLabel]) }}" aria-controls="{{ $valueId }}" aria-pressed="false" aria-label="{{ __('storefront_funnel.show_credential', ['label' => $valueLabel.' '.$accountLabel]) }}" hidden>{{ __('storefront_funnel.show') }}</button>
        @endif
        <button type="button" class="credential-action" data-credential-copy="{{ base64_encode((string) $value) }}" data-copy-success="{{ __('storefront_funnel.credential_copied', ['label' => $valueLabel]) }}" aria-label="{{ __('storefront_funnel.copy_credential', ['label' => $valueLabel.' '.$accountLabel]) }}" hidden>{{ __('storefront_funnel.copy') }}</button>
    </div>
    <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
</div>
