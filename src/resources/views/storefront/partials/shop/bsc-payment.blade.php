@php
    $bscAmount = $storefront->usdt((int) ($bscPayableAmount ?? $payment?->amount ?? 0));
    $bscProductTotal = $storefront->usdt((int) ($bscBaseAmount ?? $bscPayableAmount ?? $payment?->amount ?? 0));
    $bscStatus = $bscVerificationStatus ?? 'awaiting_transfer';
    $bscClosed = $bscVerificationClosed ?? false;
    $bscDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
    $bscStatusLabel = match ($bscStatus) {
        'awaiting_transfer' => 'Awaiting transfer',
        'tx_submitted' => 'Transaction submitted',
        'confirming' => 'Confirming',
        'confirmed' => 'Confirmed',
        'not_found' => 'Not found',
        'provider_error' => 'Provider error',
        'mismatch' => 'Mismatch',
        'manual_review' => 'Manual review',
        'transaction_already_used' => 'Transaction already used',
        'expired' => 'Expired',
        default => 'Pending verification',
    };
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="USDT BEP20 payment">
    <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Binance Payment Rail</p>
    <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">USDT BEP20 (BSC)</h2>
    <p class="mt-2 text-base font-semibold leading-7 text-[var(--sf-muted)]">Send the exact USDT amount to the snapshotted receiving address below on BNB Smart Chain.</p>

    <dl class="mt-5 grid gap-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 shadow-[4px_4px_0_var(--sf-shadow)]">
        <div>
            <dt class="text-[var(--sf-muted)]">Product total</dt>
            <dd class="font-display text-xl font-black text-[var(--sf-accent)]">{{ $bscProductTotal }}</dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">Send exactly</dt>
            <dd class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="break-all font-mono text-xl font-black text-[var(--sf-ink)]">{{ $bscAmount }}</code>
                <button type="button" class="neo-button h-11 min-w-11 w-fit bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $bscAmount }}" data-copy-success="USDT amount copied.">Copy amount</button>
            </dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">Receiving address</dt>
            <dd class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="break-all font-mono text-sm font-black text-[var(--sf-ink)]">{{ $bscReceiverAddress ?: 'Unavailable' }}</code>
                @if (filled($bscReceiverAddress))
                    <button type="button" class="neo-button h-11 min-w-11 w-fit bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $bscReceiverAddress }}" data-copy-success="BSC address copied.">Copy address</button>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">Network</dt>
            <dd class="font-black text-[var(--sf-ink)]">BNB Smart Chain (BEP20) ONLY</dd>
        </div>
    </dl>

    <div class="mt-5 rounded-lg border-[3px] border-[#9f1d2d] bg-[#fff1f3] p-4 text-sm font-black leading-6 text-[#781526]" role="alert">
        ERC20, TRC20, opBNB, native BNB, and tokens other than the approved USDT contract are not accepted.
    </div>

    <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-sm font-bold leading-6 text-[var(--sf-muted)]">
        <p class="font-black text-[var(--sf-ink)]">Payment deadline: {{ $bscDeadlineLabel ?? '30 minutes from checkout' }}</p>
        <p class="mt-1">The exact transfer is automatically confirmed after {{ $bscRequiredConfirmations }} confirmations. The deadline is fixed; submitting a hash does not extend it.</p>
        <p class="mt-2 font-black text-[var(--sf-ink)]">Status: {{ $bscStatusLabel }} ({{ $bscConfirmations }}/{{ $bscRequiredConfirmations }} confirmations)</p>
        <p class="mt-1 text-sm">{{ $statusMessage }}</p>
    </div>

    @if (filled($bscExplorerUrl))
        <a href="{{ $bscExplorerUrl }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 py-2 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">View validated transaction</a>
    @endif

    @if ($isPendingPayment && ! $bscClosed)
        <form method="POST" action="{{ $bscTxHashUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            @csrf
            <label for="bsc-tx-hash" class="font-black text-[var(--sf-ink)]">Submit BSC TX Hash</label>
            <p class="mt-1 text-sm">Use the transaction hash only, not an explorer link or screenshot.</p>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                <input id="bsc-tx-hash" name="bsc_tx_hash" inputmode="text" autocomplete="off" maxlength="66" pattern="0x[0-9a-fA-F]{64}" required class="min-h-12 min-w-0 flex-1 rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-3 font-mono text-base text-[var(--sf-ink)]" placeholder="0x...">
                <button type="submit" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">Submit TX Hash</button>
            </div>
            @error('bsc_tx_hash')
                <p class="mt-2 text-sm font-black text-red-700" role="alert">{{ $message }}</p>
            @enderror
        </form>

        <div class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4" data-shop-payment-status data-payment-kind="bsc" data-status-url="{{ $statusUrl }}" data-redirect-url="{{ $redirectUrl }}" data-terminal="{{ $isTerminal ? 'true' : 'false' }}">
            <p data-payment-status-title class="font-black text-[var(--sf-ink)]">Check BSC payment</p>
            <p data-payment-status-message class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]">The system checks the approved BSC contract, address, exact amount, and confirmations.</p>
            <p data-payment-status-announcement class="sr-only" aria-live="polite"></p>
            <button type="button" data-payment-status-check class="neo-button mt-3 min-h-12 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">Check Payment</button>
            <div data-payment-status-followup class="hidden"></div>
        </div>
    @endif

    @if ($canCancel)
        <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-base font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm('Cancel this order and release the reserved stock? Do not continue if you have already paid.');">
            @csrf
            <p class="font-black text-[var(--sf-ink)]">Have not paid yet?</p>
            <p class="mt-1">Cancel the order to release the reserved stock. Do not use this option after payment.</p>
            <button type="submit" class="mt-4 inline-flex min-h-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Cancel order</button>
        </form>
    @endif
</x-storefront.panel>
