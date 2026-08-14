<!DOCTYPE html>
<html lang="id" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head')</head>
<body class="text-[var(--sf-ink)]">
<div class="storefront-shell min-h-screen">
    <div class="pt-4">@include('storefront.partials.header')</div>
    <main class="mx-auto max-w-4xl px-5 pb-24 pt-12">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-black uppercase tracking-[.18em] text-[var(--sf-accent)]">Akun pelanggan</p>
                <h1 class="mt-2 font-display text-4xl font-black">Pesanan saya</h1>
                <p class="mt-2 text-sm font-bold text-[var(--sf-muted)]">{{ $customer->email }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('customer.claim.create') }}" class="neo-button bg-[var(--sf-accent)] px-4 py-3 text-sm font-black text-white">Klaim pesanan</a>
                <form method="POST" action="{{ route('customer.logout') }}">
                    @csrf
                    <button class="rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 py-3 text-sm font-black">Keluar</button>
                </form>
            </div>
        </div>

        <nav class="mt-8 flex flex-wrap gap-2" aria-label="Filter status pesanan">
            @foreach($filters as $filter)
                <a href="{{ $filter['url'] }}"
                   aria-current="{{ $filter['active'] ? 'page' : 'false' }}"
                   class="rounded-full border-[3px] border-[var(--sf-border)] px-4 py-2 text-sm font-black shadow-[3px_3px_0_var(--sf-shadow)] {{ $filter['active'] ? 'bg-[var(--sf-accent)] text-white' : 'bg-white text-[var(--sf-ink)]' }}">
                    {{ $filter['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="mt-6 space-y-4">
            @forelse($orders as $order)
                @php
                    $orderStatusLabel = match ($order->order_status?->value) {
                        'draft' => 'Draft',
                        'pending_payment' => 'Menunggu pembayaran',
                        'processing' => 'Diproses',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        'expired' => 'Kedaluwarsa',
                        default => 'Tidak diketahui',
                    };
                    $paymentStatusLabel = match ($order->payment_status?->value) {
                        'pending' => 'Menunggu pembayaran',
                        'paid' => 'Dibayar',
                        'failed' => 'Gagal',
                        'expired' => 'Kedaluwarsa',
                        'refunded' => 'Dikembalikan',
                        default => 'Tidak diketahui',
                    };
                @endphp
                <article class="rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)]">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[.16em] text-[var(--sf-muted)]">Invoice</p>
                            <h2 class="mt-1 font-display text-xl font-black">{{ $order->invoice_number }}</h2>
                        </div>
                        <p class="font-display text-xl font-black text-[var(--sf-accent)]">{{ number_format($order->total_amount, 0, ',', '.') }} {{ $order->currency }}</p>
                    </div>
                    <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                            <dt class="text-xs font-black uppercase tracking-[.14em] text-[var(--sf-muted)]">Status Order</dt>
                            <dd class="mt-1 text-sm font-black">{{ $orderStatusLabel }}</dd>
                        </div>
                        <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                            <dt class="text-xs font-black uppercase tracking-[.14em] text-[var(--sf-muted)]">Status Pembayaran</dt>
                            <dd class="mt-1 text-sm font-black">{{ $paymentStatusLabel }}</dd>
                        </div>
                    </dl>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm font-bold text-[var(--sf-muted)]">{{ $order->created_at?->format('d M Y H:i') }}</p>
                        <a href="{{ route('customer.orders.show', $order) }}" class="neo-button inline-flex items-center bg-[var(--sf-accent)] px-4 py-2 text-sm font-black text-white">Lihat detail</a>
                    </div>
                </article>
            @empty
                @php
                    $activeFilterLabel = collect($filters)->firstWhere('key', $activeFilter)['label'] ?? $activeFilter;
                @endphp
                <div class="rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-8 text-center font-bold">
                    @if($activeFilter === 'all')
                        Belum ada riwayat pesanan di akun ini.
                    @else
                        Tidak ada pesanan pada filter "{{ $activeFilterLabel }}".
                    @endif
                </div>
            @endforelse
        </div>

        @if($orders->hasPages())
            <nav class="mt-8" aria-label="Paginasi pesanan">{{ $orders->links() }}</nav>
        @endif
    </main>
</div>
</body>
</html>
