<x-storefront.panel as="section" class="mt-5" aria-label="Pembayaran Binance Pay">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">International payment</p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">Binance Pay (USDT)</h2>
            <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Kirim nominal USDT tepat seperti di bawah melalui Binance Pay. Pembayaran diperiksa manual oleh admin.</p>
        </div>
        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
            {{ $isPaid ? 'Terverifikasi' : 'Menunggu verifikasi' }}
        </span>
    </div>

    <dl class="mt-5 space-y-3 text-sm font-bold">
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">Kirim tepat</dt>
            <dd class="text-right font-display text-2xl font-black text-[var(--sf-accent)]">{{ $storefront->usdt((int) $payment->amount) }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4">
            <dt class="text-[var(--sf-muted)]">Binance Pay ID</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $binancePayId }}</dd>
        </div>
    </dl>

    @if ($isPendingPayment)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            <p class="font-black text-[var(--sf-ink)]">Setelah membayar, kirim screenshot bukti pembayaran ke bot Telegram.</p>
            <p class="mt-2">Gunakan caption: <code class="rounded bg-white px-1">/payment {{ $order->invoice_number }}</code>. Jangan mengirim nominal yang berbeda.</p>
        </div>
    @endif
</x-storefront.panel>
