<x-storefront.panel as="section" class="mt-6" aria-label="Ringkasan invoice">
    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Invoice</p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ $order->invoice_number }}</h2>
            <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                Stok sudah dicadangkan untuk {{ $order->customer?->first_name ?? 'Pelanggan' }}.
            </p>
        </div>

        <dl class="grid gap-2 text-sm font-bold sm:min-w-52">
            <div class="flex items-center justify-between gap-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">
                <dt class="text-[var(--sf-muted)]">Status order</dt>
                <dd class="font-black text-[var(--sf-ink)]">{{ $order->order_status?->value ?? '-' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">
                <dt class="text-[var(--sf-muted)]">Status pembayaran</dt>
                <dd class="font-black text-[var(--sf-ink)]">{{ $payment?->status?->value ?? $order->payment_status?->value ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-6 border-t-[3px] border-[var(--sf-border)] pt-5">
        <h3 class="font-display text-xl font-black text-[var(--sf-ink)]">Produk</h3>
        <ul class="mt-4 space-y-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
            @foreach ($order->orderItems as $item)
                <li class="flex items-center justify-between gap-4 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 shadow-[3px_3px_0_var(--sf-shadow)]">
                    <span class="font-black text-[var(--sf-ink)]">{{ $item->product_name }}</span>
                    <span>x{{ $item->quantity }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</x-storefront.panel>
