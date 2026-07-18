@php
    $binanceAmount = $storefront->usdt((int) $payment->amount);
    $paymentCaption = '/payment '.$order->invoice_number;
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="Pembayaran Binance Pay">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">International payment</p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">Binance Pay (USDT)</h2>
            <p class="mt-2 text-base font-semibold leading-7 text-[var(--sf-muted)]">Kirim nominal USDT tepat seperti di bawah melalui Binance Pay. Pembayaran diperiksa manual oleh admin.</p>
        </div>
        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-sm font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
            {{ $isPaid ? 'Terverifikasi' : 'Menunggu verifikasi' }}
        </span>
    </div>

    <dl class="mt-5 space-y-3 text-sm font-bold">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4" data-copy-group>
            <dt class="text-[var(--sf-muted)]">Kirim tepat</dt>
            <dd class="flex items-center gap-2 text-left sm:text-right">
                <span class="font-display text-2xl font-black text-[var(--sf-accent)]">{{ $binanceAmount }}</span>
                <button type="button" class="neo-button h-11 min-w-11 bg-[var(--sf-surface)] px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $binanceAmount }}" data-copy-success="Nominal USDT tersalin.">Salin</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
        <div class="flex flex-col gap-2 border-t-[3px] border-[var(--sf-border)] pt-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4" data-copy-group>
            <dt class="text-[var(--sf-muted)]">Binance Pay ID</dt>
            <dd class="flex items-center gap-2 text-left sm:text-right">
                <span class="font-black text-[var(--sf-ink)]">{{ $binancePayId }}</span>
                <button type="button" class="neo-button h-11 min-w-11 bg-[var(--sf-surface)] px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $binancePayId }}" data-copy-success="Binance Pay ID tersalin.">Salin</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
    </dl>

    @if ($isPendingPayment)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            <p class="font-black text-[var(--sf-ink)]">Setelah membayar, kirim screenshot bukti pembayaran ke bot Telegram.</p>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center" data-copy-group>
                <p class="min-w-0">Gunakan caption: <code class="rounded bg-white px-1">{{ $paymentCaption }}</code>.</p>
                <button type="button" class="neo-button h-11 shrink-0 bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $paymentCaption }}" data-copy-success="Caption pembayaran tersalin.">Salin caption</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </div>
            <p class="mt-2">Jangan mengirim nominal yang berbeda.</p>
        </div>
        @if ($canCancel)
            <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm('Batalkan order ini dan lepas stok? Jangan lanjutkan jika kamu sudah membayar.');">
                @csrf
                <p class="font-black text-[var(--sf-ink)]">Belum membayar?</p>
                <p>Batalkan order agar stok dilepas kembali. Jangan gunakan tombol ini jika kamu sudah membayar.</p>
                <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">
                    Batalkan order &amp; lepas stok
                </button>
            </form>
        @endif
    @endif
</x-storefront.panel>
