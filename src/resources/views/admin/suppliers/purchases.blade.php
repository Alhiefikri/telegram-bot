@extends('admin.suppliers.layout')
@section('title', 'Monitor pembelian supplier')
@section('content')
<x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mb-3" aria-labelledby="supplier-purchases-title">
    <p class="text-xs font-medium text-admin-muted">Supplier</p>
    <h2 id="supplier-purchases-title" class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Monitor pembelian supplier</h2>
    <p class="mt-2 text-sm leading-6 text-admin-muted">Cari invoice, selesaikan pengiriman pelanggan, lalu tangani saldo supplier secara terpisah.</p>
    <form method="GET" action="{{ route('admin.suppliers.purchases') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <x-admin-dashboard.field-label class="min-w-0 flex-1">
            Nomor invoice
            <x-admin-dashboard.input name="invoice" :value="$invoice" maxlength="100" placeholder="Tempel nomor invoice lengkap" autocomplete="off" class="mt-1 border-admin-muted!" />
        </x-admin-dashboard.field-label>
        <x-admin-dashboard.button type="submit" variant="primary">Cari invoice</x-admin-dashboard.button>
        @if($invoice !== '')
            <x-admin-dashboard.button :href="route('admin.suppliers.purchases')" variant="neutral">Lihat semua kendala</x-admin-dashboard.button>
        @endif
    </form>
</x-admin-dashboard.card>
@if(session('supplier_sync_warning'))
    <x-admin-dashboard.notice tone="warning" class="mb-3">{{ session('supplier_sync_warning') }}</x-admin-dashboard.notice>
@endif
<section class="mt-3 grid gap-3">
    <h2 class="text-lg font-semibold text-admin-ink">{{ $invoice !== '' ? 'Penanganan invoice' : 'Invoice yang perlu ditangani' }}</h2>
    @forelse($fulfillments as $fulfillment)
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
            <h3 class="break-all text-base font-semibold text-admin-ink">{{ $fulfillment->order->invoice_number }}</h3>
            <p class="mt-1 text-sm text-admin-ink">{{ $fulfillment->orderItem->product_name }} · {{ $fulfillment->quantity }} unit</p>
            <a class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline" href="{{ route('admin.orders.show', $fulfillment->order_id) }}">Lihat detail pesanan dan pengiriman</a>
            @if($invoice !== '')
                @include('admin.suppliers.invoice-recovery')
            @else
                @php($holdLabel = $fulfillment->unpaid_reservation
                    ? 'Kapasitas supplier direservasi untuk pesanan yang belum dibayar; belum ada pembelian'.($fulfillment->order->expires_at === null ? '' : ' (lepas otomatis paling lambat '.$fulfillment->order->expires_at->timezone(config('app.timezone'))->format('d M Y H:i:s T').')')
                    : ($fulfillment->reserved_cost_micros > 0 ? 'Masih ada dana supplier ditahan' : 'Tanpa hold supplier'))
                <p class="mt-1 text-sm text-admin-ink">{{ $fulfillment->state === 'delivered' ? 'Akun pelanggan sudah dipenuhi' : $fulfillment->remaining_quantity.' akun belum dipenuhi' }} · {{ $holdLabel }}</p>
                <x-admin-dashboard.button :href="route('admin.suppliers.purchases', ['invoice' => $fulfillment->order->invoice_number])" variant="primary" class="mt-3">Tangani invoice</x-admin-dashboard.button>
            @endif
            <details class="mt-4 border-t border-admin-line pt-3">
                <summary class="min-h-11 cursor-pointer text-sm font-medium text-admin-ink">Pemeriksaan lanjutan supplier</summary>
                <p class="mb-3 text-sm text-admin-muted">Fulfillment #{{ $fulfillment->id }} · {{ $fulfillment->state }}. Gunakan bagian ini untuk mencocokkan bukti transaksi supplier, bukan untuk membeli ulang saat hasilnya belum jelas.</p>
            @if($fulfillment->state === 'delivered')
                <x-admin-dashboard.notice tone="warning" class="mt-3">Delivery customer sudah selesai. Pulihkan hanya bukti pembelian asli; jangan membeli atau mengirim kredensial pengganti lagi.</x-admin-dashboard.notice>
            @endif
            @if($fulfillment->currentPurchase?->latestHistoryReconciliation)
                @php($history = $fulfillment->currentPurchase->latestHistoryReconciliation)
                <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-sm">
                    <p class="font-semibold text-admin-ink">Rekonsiliasi riwayat order #{{ $history->id }} · {{ $history->outcome }}</p>
                    <p class="mt-1 text-admin-ink">Dispatch {{ $history->dispatch_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} · scan {{ $history->observed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} · jendela {{ $history->history_timezone }}</p>
                    <p class="mt-1 text-admin-ink">{{ $history->row_count }} baris dari batas {{ $history->requested_limit }} · rentang {{ $history->coverage_start?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? ($history->row_count === 0 ? 'riwayat kosong' : 'coverage tidak terverifikasi') }} s/d {{ $history->coverage_end?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '—' }} · {{ $history->error_code ?? 'tanpa error' }}</p>
                    @if($history->coverage_kind && $fulfillment->currentPurchase->supplier?->provider === 'csxstore')
                        <p class="mt-1 text-admin-ink">Coverage: {{ $history->coverage_kind }} · first page only / cakupan tidak lengkap · timezone unknown (offset-free), waktu mentah bukan instant. Capture {{ $history->observed_at?->toIso8601String() }}.</p>
                        <p class="mt-1 text-admin-ink">Satu kandidat sekalipun bukan identitas definitif. SKU/jumlah/biaya/waktu yang sama tidak membuktikan kepemilikan. Operator harus memilih dan mengonfirmasi; GET exact akan memeriksa bukti kembali.</p>
                        @foreach($history->candidate_facts ?? [] as $candidate)
                            <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface p-3">
                                <p class="text-admin-ink">Order {{ $candidate['order_id'] }} · SKU {{ $candidate['product_id'] }} · {{ $candidate['quantity'] }} unit · {{ $candidate['total_micros'] }} micros {{ $candidate['currency'] }} · {{ $candidate['status'] }}</p>
                                <p class="mt-1 text-admin-ink">Waktu mentah: {{ $candidate['created_at_raw'] }} · {{ $candidate['timezone_qualification'] }}</p>
                                <p class="mt-1 text-admin-ink">Kecocokan: {{ implode(', ', $candidate['match_reasons']) }} · Batas bukti: {{ implode(', ', $candidate['limitations']) }}</p>
                                @if($history->outcome === 'candidate_orders' && $history->observed_at->greaterThanOrEqualTo(now()->subMinutes(15)) && $fulfillment->currentPurchase->supplier_order_id === null)
                                    <form method="POST" action="{{ route('admin.suppliers.operate', $fulfillment) }}" class="mt-2 grid gap-2">
                                        @csrf
                                        <input type="hidden" name="action" value="history_confirm">
                                        <input type="hidden" name="purchase_id" value="{{ $history->supplier_purchase_id }}">
                                        <input type="hidden" name="purchase_version" value="{{ $history->purchase_version }}">
                                        <input type="hidden" name="history_reconciliation_id" value="{{ $history->id }}">
                                        <input type="hidden" name="supplier_order_id" value="{{ $candidate['order_id'] }}">
                                        <x-admin-dashboard.field-label>
                                            Catatan atribusi operator (wajib)
                                            <x-admin-dashboard.textarea name="proof_note" required maxlength="4000" class="mt-1" />
                                        </x-admin-dashboard.field-label>
                                        <x-admin-dashboard.button type="submit" variant="primary" size="sm">Pilih {{ $candidate['order_id'] }} dan konfirmasi debit melalui GET exact</x-admin-dashboard.button>
                                    </form>
                                @else
                                    <x-admin-dashboard.notice tone="warning" class="mt-2">Kandidat expired / tidak tersedia; baca ulang histori sebelum konfirmasi.</x-admin-dashboard.notice>
                                @endif
                            </div>
                        @endforeach
                    @endif
                    @if($history->candidate_order_ids && $fulfillment->currentPurchase->supplier?->provider !== 'csxstore')
                        <p class="mt-1 break-all text-admin-ink">Order kandidat (perlu GET exact dan cocokkan jumlah/biaya): {{ implode(', ', $history->candidate_order_ids) }}</p>
                    @endif
                </div>
            @endif
                <div class="mt-3 border-t border-admin-line pt-3">
                    <form method="POST" action="{{ route('admin.suppliers.operate', $fulfillment) }}" class="grid gap-3">
                        @csrf
                        <input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id }}">
                        <input type="hidden" name="purchase_version" value="{{ $fulfillment->currentPurchase?->updated_at?->format('Y-m-d H:i:s') }}">
                        <x-admin-dashboard.field-label>
                            Tindakan
                            <x-admin-dashboard.select class="mt-1" name="action">
                                @if($fulfillment->state !== 'delivered')
                                    <option value="retry">Coba setelah isi saldo (hanya belum terkirim / terbukti ditolak)</option>
                                @endif
                                <option value="recover">Pulihkan dengan ID supplier order yang terverifikasi (GET)</option>
                                @if($fulfillment->currentPurchase && in_array($fulfillment->currentPurchase->state, ['ambiguous', 'review'], true) && $fulfillment->currentPurchase->supplier_order_id === null && in_array($fulfillment->currentPurchase->supplier?->provider, ['toolorax', 'csxstore'], true))
                                    <option value="history">Baca riwayat order {{ $fulfillment->currentPurchase->supplier->provider === 'csxstore' ? 'CSxStore (halaman pertama)' : 'ToolOraX' }} (GET, maks. 100)</option>
                                @endif
                                @if($fulfillment->currentPurchase && in_array($fulfillment->currentPurchase->state, ['ambiguous', 'review'], true) && $fulfillment->currentPurchase->supplier_order_id === null)
                                    <option value="debit_confirmed">Konfirmasi debit dengan order ID, jumlah dan biaya</option>
                                    <option value="no_debit">Konfirmasi no-debit dengan bukti provider</option>
                                @endif
                            </x-admin-dashboard.select>
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            ID supplier order terverifikasi
                            <x-admin-dashboard.input name="supplier_order_id" maxlength="128" autocomplete="off" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Jumlah pada bukti debit
                            <x-admin-dashboard.input type="number" name="debit_quantity" min="1" max="10000" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Biaya aktual pada bukti debit (micros mata uang supplier)
                            <x-admin-dashboard.input type="number" name="debit_cost_micros" min="1" step="1" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Sumber bukti no-debit
                            <x-admin-dashboard.select class="mt-1" name="proof_source">
                                <option value="provider_case">Konfirmasi kasus dari provider</option>
                                <option value="provider_statement">Pernyataan / riwayat provider</option>
                                @if($fulfillment->currentPurchase?->latestHistoryReconciliation?->outcome === \App\Models\SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER)
                                    <option value="provider_history">Hasil rekonsiliasi riwayat order #{{ $fulfillment->currentPurchase->latestHistoryReconciliation->id }}</option>
                                @endif
                            </x-admin-dashboard.select>
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Referensi bukti provider
                            <x-admin-dashboard.input name="proof_reference" maxlength="255" autocomplete="off" placeholder="ID case / dokumen provider" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            ID hasil history no-order (khusus bukti provider_history)
                            <x-admin-dashboard.input type="number" name="history_reconciliation_id" min="1" value="{{ $fulfillment->currentPurchase?->latestHistoryReconciliation?->outcome === \App\Models\SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER ? $fulfillment->currentPurchase->latestHistoryReconciliation->id : '' }}" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        @if($fulfillment->currentPurchase?->supplier?->provider === 'toolorax')
                            <x-admin-dashboard.field-label>
                                Timezone timestamp provider (IANA, hanya saat baca history)
                                <x-admin-dashboard.input name="history_timezone" maxlength="64" placeholder="Contoh: Asia/Jakarta" autocomplete="off" class="mt-1" />
                                <small class="mt-1 block text-xs font-normal leading-5 text-admin-muted">Masukkan timezone timestamp ShopBot yang sudah dikonfirmasi dari provider. Timestamp tanpa offset yang timezone-nya tidak diketahui tidak membuktikan coverage.</small>
                            </x-admin-dashboard.field-label>
                        @endif
                        <x-admin-dashboard.field-label>
                            Catatan verifikasi / bukti
                            <x-admin-dashboard.textarea name="proof_note" maxlength="4000" rows="4" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.button type="submit" variant="primary" size="sm">Jalankan tindakan fulfillment</x-admin-dashboard.button>
                    </form>
                </div>
            </details>
        </x-admin-dashboard.card>
    @empty
        <x-admin-dashboard.notice tone="info">{{ $invoice !== '' ? 'Tidak ada penanganan supplier untuk nomor invoice ini. Periksa nomor invoice lengkap atau buka detail pesanan.' : 'Tidak ada invoice dengan kendala supplier saat ini.' }}</x-admin-dashboard.notice>
    @endforelse
    {{ $fulfillments->links() }}
</section>
<details class="mt-5">
    <summary class="min-h-11 cursor-pointer text-lg font-semibold text-admin-ink">Riwayat pembelian supplier</summary>
    <section class="mt-3 grid gap-3">
    @foreach($purchases as $purchase)
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
            <h3 class="text-base font-semibold text-admin-ink">Pembelian #{{ $purchase->id }} · <x-admin-dashboard.badge tone="slate">{{ $purchase->state }}</x-admin-dashboard.badge></h3>
            <p class="mt-1 break-words text-sm text-admin-ink">Fulfillment #{{ $purchase->supplier_fulfillment_id }} · Supplier #{{ $purchase->supplier_id }} · Referensi {{ $purchase->reference }} · Supplier order {{ $purchase->supplier_order_id ?? 'Belum diketahui' }}</p>
            <p class="mt-1 text-sm text-admin-ink">{{ $purchase->quantity }} unit · Estimasi micro-unit {{ $purchase->expected_cost_micros }} · Aktual {{ $purchase->actual_cost_micros ?? 'Belum terbukti' }} · {{ $purchase->updated_at }}</p>
        </x-admin-dashboard.card>
    @endforeach
    {{ $purchases->links() }}
    </section>
</details>
@endsection
