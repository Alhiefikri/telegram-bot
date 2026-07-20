<x-storefront.panel as="section" class="mt-6" aria-label="Ringkasan invoice">
    <div class="min-w-0">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Invoice</p>
        <code class="mt-2 block max-w-full break-all font-mono text-base font-black leading-6 text-[var(--sf-ink)] sm:text-lg">{{ $order->invoice_number }}</code>
        <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
            @if ($isTerminal && ! $isPaid)
                Stok tidak lagi dicadangkan untuk invoice ini.
            @else
                Stok sudah dicadangkan untuk {{ $order->customer?->first_name ?? 'Pelanggan' }}.
            @endif
        </p>

        <dl class="mt-5 grid gap-2 text-sm font-bold sm:grid-cols-2">
            <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2.5">
                <dt class="text-[var(--sf-muted)]">Status order</dt>
                <dd class="break-words text-right font-black text-[var(--sf-ink)]">{{ $order->order_status?->value ?? '-' }}</dd>
            </div>
            <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2.5">
                <dt class="text-[var(--sf-muted)]">Status pembayaran</dt>
                <dd class="break-words text-right font-black text-[var(--sf-ink)]">{{ $payment?->status?->value ?? $order->payment_status?->value ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-6 border-t-[3px] border-[var(--sf-border)] pt-5">
        <h3 class="font-display text-xl font-black text-[var(--sf-ink)]">Produk</h3>
        <ul class="mt-4 space-y-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
            @foreach ($order->orderItems as $item)
                <li class="flex min-w-0 items-start justify-between gap-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 shadow-[3px_3px_0_var(--sf-shadow)]">
                    <span class="min-w-0 break-words font-black text-[var(--sf-ink)]">{{ $item->product_name }}</span>
                    <span class="shrink-0 rounded-md bg-[var(--sf-accent-soft)] px-2 py-0.5 font-black text-[var(--sf-ink)]">x{{ $item->quantity }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</x-storefront.panel>
