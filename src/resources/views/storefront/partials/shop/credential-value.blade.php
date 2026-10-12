<div class="min-w-0" data-copy-group @if($sensitive) data-credential-secret @endif>
    <p id="{{ $valueId }}-label" class="text-sm font-semibold text-[var(--sf-ink)]">{{ $valueLabel }}</p>
    @if ($sensitive)
        <p class="mt-2 text-sm text-[var(--sf-muted)]" data-secret-mask hidden>Disembunyikan</p>
    @endif
    <pre id="{{ $valueId }}" class="credential-value mt-2 max-w-full whitespace-pre-wrap break-all font-mono text-sm leading-6 text-[var(--sf-ink)]" aria-labelledby="{{ $valueId }}-label" @if($sensitive) data-secret-value hidden tabindex="0" @endif>@if(! $sensitive){{ $value }}@endif</pre>
    @if ($sensitive)
        <noscript>
            <details class="mt-2">
                <summary class="credential-action cursor-pointer" aria-label="Tampilkan {{ $valueLabel }} {{ $accountLabel }}">Tampilkan / Sembunyikan</summary>
                <pre class="credential-value mt-2 max-w-full whitespace-pre-wrap break-all font-mono text-sm leading-6 text-[var(--sf-ink)]" tabindex="0"><span>{{ $value }}</span></pre>
            </details>
        </noscript>
    @endif
    <div class="mt-2 flex flex-wrap gap-2">
        @if ($sensitive)
            <button type="button" class="credential-action" data-secret-toggle data-secret-label="{{ $valueLabel }} {{ $accountLabel }}" aria-controls="{{ $valueId }}" aria-pressed="false" aria-label="Tampilkan {{ $valueLabel }} {{ $accountLabel }}" hidden>Tampilkan</button>
        @endif
        <button type="button" class="credential-action" data-credential-copy="{{ base64_encode((string) $value) }}" data-copy-success="{{ $valueLabel }} tersalin." aria-label="Salin {{ $valueLabel }} {{ $accountLabel }}" hidden>Salin</button>
    </div>
    <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
</div>
