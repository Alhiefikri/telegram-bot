@php
    $hasNativeQris = $isPendingPayment && filled($qrisImageDataUri);
    $paymentDeadline = $payment?->provider_expires_at ?? $payment?->expired_at ?? $order->expires_at;
    $paymentDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="Pembayaran QRIS">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Pembayaran QRIS</p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">QRIS invoice</h2>
            <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                Bayar sesuai total QRIS final. Jangan memakai total produk sebagai total final jika biaya QRIS belum muncul.
            </p>
        </div>

        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
            {{ $isPaid ? 'Terverifikasi' : ($isPendingPayment ? 'Menunggu bayar' : ($payment?->status?->value ?? 'Belum tersedia')) }}
        </span>
    </div>

    <dl class="mt-5 space-y-3 text-sm font-bold">
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">Total produk</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $storefront->idr($order->total_amount) }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">Biaya QRIS</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $payment?->provider_fee !== null ? $storefront->idr($payment->provider_fee) : '-' }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4 text-lg">
            <dt class="font-black text-[var(--sf-ink)]">Total bayar QRIS</dt>
            <dd class="text-right font-display text-2xl font-black text-[var(--sf-accent)]">{{ $payableAmount !== null ? $storefront->idr($payableAmount) : '-' }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">Batas bayar</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $paymentDeadlineLabel ?? '-' }}</dd>
        </div>
    </dl>

    @if (! $payableAmountFromProvider)
        <p class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-black leading-6 text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            Total final QRIS belum tersedia. Jangan bayar sebelum QRIS berhasil dibuat ulang.
        </p>
    @endif

    @if ($hasNativeQris)
        <div class="payment-qris-stage relative mt-6 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-5 shadow-[6px_6px_0_var(--sf-shadow)]">
            <div class="payment-qris-poster mx-auto max-w-sm">
                <div class="qris-frame relative mx-auto max-w-[300px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[7px_7px_0_var(--sf-shadow)]">
                    <img src="{{ $qrisImageDataUri }}" alt="QRIS invoice {{ $order->invoice_number }}" class="relative z-10 aspect-square w-full object-contain">
                    <div class="qris-scan-line" aria-hidden="true"></div>
                </div>
                <div class="payment-qris-corners" aria-hidden="true"></div>
            </div>
        </div>
    @elseif ($isPendingPayment)
        <p class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            QRIS belum tersedia di invoice ini. Simpan link invoice dan hubungi admin jika status tidak berubah.
        </p>
    @endif

    @if ($isPendingPayment)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]"
             data-shop-payment-status
             data-status-url="{{ $statusUrl }}"
             data-redirect-url="{{ $redirectUrl }}">
            <p class="font-black text-[var(--sf-ink)]" data-payment-status-title>Cek status pembayaran</p>
            <p data-payment-status-message>Setelah membayar, tekan tombol ini untuk mengecek status QRIS.</p>
            <p class="sr-only" aria-live="polite" data-payment-status-announcement></p>
            <button type="button" class="mt-4 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-sm font-black text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto" data-payment-status-check>
                Cek status pembayaran
            </button>
            <div class="mt-4 hidden flex-wrap gap-3" data-payment-status-followup>
                <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">Belanja lagi</a>
                <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Butuh bantuan?</a>
            </div>
        </div>
        @if ($canCancel)
            <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm('Batalkan order ini dan lepas stok? Jangan lanjutkan jika kamu sudah membayar.');">
                @csrf
                <p class="font-black text-[var(--sf-ink)]">Belum membayar?</p>
                <p>Batalkan order agar stok dilepas kembali. Jangan gunakan tombol ini jika kamu sudah membayar.</p>
                <button type="submit" class="mt-4 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">
                    Batalkan order &amp; lepas stok
                </button>
            </form>
        @endif
    @elseif (! $isPaid)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" data-terminal="true">
            <p class="font-black text-[var(--sf-ink)]">Status pembayaran final</p>
            <p>{{ $statusMessage }}</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">Belanja lagi</a>
                <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Butuh bantuan?</a>
            </div>
        </div>
    @endif
</x-storefront.panel>
