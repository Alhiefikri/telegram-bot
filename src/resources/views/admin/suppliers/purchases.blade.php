@extends('admin.suppliers.layout')
@section('title', 'Monitor pembelian supplier')
@section('content')
@php
    $fulfillmentLabels = [
        'reserved' => 'Dana ditahan',
        'pending' => 'Menunggu diproses',
        'purchasing' => 'Sedang membeli',
        'awaiting_delivery' => 'Menunggu data supplier',
        'delivered' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'review' => 'Perlu tindakan manual',
    ];
    $purchaseLabels = [
        'reserved' => 'Dana ditahan',
        'sending' => 'Sedang membeli',
        'awaiting_delivery' => 'Menunggu data supplier',
        'ambiguous' => 'Perlu diperiksa — hasil belum pasti',
        'review' => 'Perlu tindakan manual',
        'delivered' => 'Selesai',
        'settled' => 'Selesai (ekonomi)',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ];
    $stateTone = fn (string $state) => match ($state) {
        'delivered', 'settled' => 'emerald',
        'cancelled', 'rejected' => 'slate',
        'ambiguous', 'review' => 'rose',
        default => 'amber',
    };
@endphp

<x-admin-dashboard.card padding="compact" shadow="sm" class="min-w-0">
    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-brand-muted">Hanya metadata privat</p>
    <h2 class="mt-1 font-display text-lg font-black uppercase text-neo-ink">Pembelian yang perlu diperiksa</h2>
    <p class="mt-2 text-xs font-bold leading-5 text-brand-muted">
        Halaman ini tidak menampilkan API key, payload mentah, atau kredensial pembeli. Hasil yang belum pasti tidak boleh langsung dibeli ulang, dialihkan, atau direfund. Tindakan di bawah memerlukan konfirmasi password dalam 15 menit terakhir dan tetap diverifikasi oleh sistem.
    </p>
</x-admin-dashboard.card>

<section class="grid gap-3" aria-labelledby="supplier-fulfillments-title">
    <h2 id="supplier-fulfillments-title" class="font-display text-base font-black uppercase text-neo-ink">Pesanan pelanggan yang belum selesai</h2>

    @forelse ($fulfillments as $fulfillment)
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-display text-sm font-black text-neo-ink">Pesanan {{ $fulfillment->order_id }}</p>
                    <p class="mt-1 text-xs font-bold text-brand-muted">Item #{{ $fulfillment->order_item_id }} · {{ $fulfillment->quantity }} unit</p>
                </div>
                <x-admin-dashboard.badge :tone="$stateTone($fulfillment->state)">{{ $fulfillmentLabels[$fulfillment->state] ?? $fulfillment->state }}</x-admin-dashboard.badge>
            </div>

            <div class="mt-2 flex flex-wrap gap-3">
                <a class="text-xs font-black underline" href="{{ route('admin.orders.show', $fulfillment->order_id) }}">Lihat pesanan / proses refund lewat alur keuangan</a>
            </div>

            @if ($fulfillment->state === 'delivered')
                <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">
                    Pengiriman ke pembeli sudah selesai. Pulihkan hanya bukti pembelian aslinya; jangan membeli ulang atau mengirim kredensial pengganti.
                </x-admin-dashboard.notice>
            @endif

            <details class="mt-3">
                <summary class="cursor-pointer font-bold">Tindakan aman</summary>
                <form method="POST" action="{{ route('admin.suppliers.operate', $fulfillment) }}" class="mt-3 grid gap-3">
                    @csrf
                    <input type="hidden" name="context" value="purchases">

                    <div class="grid gap-1">
                        <x-admin-dashboard.field-label for="fulfillment-action-{{ $fulfillment->id }}">Tindakan</x-admin-dashboard.field-label>
                        <x-admin-dashboard.select id="fulfillment-action-{{ $fulfillment->id }}" name="action">
                            @if ($fulfillment->state !== 'delivered')
                                <option value="retry">Coba lagi setelah saldo diisi (hanya bila belum terkirim / terbukti ditolak)</option>
                            @endif
                            <option value="recover">Ambil ulang dengan ID pesanan supplier yang sudah dipastikan</option>
                            @if ($fulfillment->state !== 'delivered')
                                <option value="manual">Kirim kredensial manual (pembelian asli tetap harus diselesaikan)</option>
                            @endif
                        </x-admin-dashboard.select>
                    </div>

                    <div class="grid gap-1">
                        <x-admin-dashboard.field-label for="fulfillment-supplier-order-{{ $fulfillment->id }}">ID pesanan supplier (untuk tindakan Ambil ulang)</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="fulfillment-supplier-order-{{ $fulfillment->id }}" name="supplier_order_id" maxlength="128" autocomplete="off" />
                    </div>

                    @if ($fulfillment->state !== 'delivered')
                        <div class="grid gap-1">
                            <x-admin-dashboard.field-label for="fulfillment-credentials-{{ $fulfillment->id }}">Kredensial manual, satu unit per baris (tidak disimpan di sesi)</x-admin-dashboard.field-label>
                            <x-admin-dashboard.textarea id="fulfillment-credentials-{{ $fulfillment->id }}" name="credentials" rows="4" maxlength="64000" autocomplete="off" spellcheck="false" />
                        </div>
                    @endif

                    <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="justify-self-start">Jalankan tindakan</x-admin-dashboard.button>
                </form>
            </details>
        </x-admin-dashboard.card>
    @empty
        <x-admin-dashboard.empty-state title="Tidak ada pesanan supplier yang tertahan." body="Semua pembelian supplier untuk pesanan pelanggan sudah selesai." />
    @endforelse

    <div>{{ $fulfillments->links() }}</div>
</section>

<section class="grid gap-3" aria-labelledby="supplier-purchases-title">
    <h2 id="supplier-purchases-title" class="font-display text-base font-black uppercase text-neo-ink">Riwayat pembelian (hanya baca)</h2>

    @forelse ($purchases as $purchase)
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-display text-sm font-black text-neo-ink">Pembelian #{{ $purchase['id'] }}</p>
                    <p class="mt-1 break-words text-xs font-bold text-brand-muted">
                        Pesanan terkait #{{ $purchase['fulfillment_id'] }}
                        · {{ $purchase['supplier_name'] ? $purchase['supplier_name'] : 'Supplier #'.$purchase['supplier_id'] }}
                        @if ($purchase['supplier_order_id']) · ID supplier {{ $purchase['supplier_order_id'] }} @endif
                    </p>
                </div>
                <x-admin-dashboard.badge :tone="$stateTone($purchase['state'])">{{ $purchaseLabels[$purchase['state']] ?? $purchase['state'] }}</x-admin-dashboard.badge>
            </div>
            <p class="mt-2 text-xs font-bold text-brand-muted">
                {{ $purchase['quantity'] }} unit
                · Perkiraan biaya {{ $purchase['expected_cost'] ?? 'belum diketahui' }}
                · Biaya nyata {{ $purchase['actual_cost'] ?? 'belum terbukti' }}
                @if ($purchase['updated_at']) · diperbarui {{ $purchase['updated_at'] }} @endif
            </p>
        </x-admin-dashboard.card>
    @empty
        <x-admin-dashboard.empty-state title="Belum ada riwayat pembelian supplier." />
    @endforelse

    <div>{{ $purchases->links() }}</div>
</section>
@endsection
