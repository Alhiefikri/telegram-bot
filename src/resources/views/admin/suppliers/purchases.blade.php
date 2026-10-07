@extends('admin.suppliers.layout')
@section('title', 'Monitor pembelian supplier')
@section('content')
<p>Metadata privat saja: tidak ada API key, payload mentah, atau kredensial pembeli. Ambiguitas tidak mengizinkan pembelian ulang, fallback, atau refund sebelum ada bukti. Tindakan memerlukan konfirmasi password dalam 15 menit dan tetap diperiksa oleh layanan fulfillment. Riwayat order ToolOraX memakai satu GET maksimal 100 baris; hasil penuh, waktu dispatch, dan timezone harus cocok sebelum no-debit dapat disahkan.</p>
<section class="grid gap-3"><h2 class="font-bold">Fulfillment / komitmen belum terselesaikan</h2>
@foreach($fulfillments as $fulfillment)
<article class="rounded-2xl border-2 bg-white p-4"><h3 class="font-bold">Fulfillment #{{ $fulfillment->id }} · {{ $fulfillment->state }}</h3><p class="break-words">Pesanan {{ $fulfillment->order_id }} · Item #{{ $fulfillment->order_item_id }} · {{ $fulfillment->quantity }} unit</p><a class="underline" href="{{ route('admin.orders.show', $fulfillment->order_id) }}">Pesanan / refund melalui alur keuangan yang ada</a>
@if($fulfillment->state === 'delivered')
<p class="mt-3">Delivery customer sudah selesai. Pulihkan hanya bukti pembelian asli; jangan membeli atau mengirim kredensial pengganti lagi.</p>
@endif
@if($fulfillment->currentPurchase?->latestHistoryReconciliation)
@php($history = $fulfillment->currentPurchase->latestHistoryReconciliation)
<div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
<p class="font-bold">Rekonsiliasi riwayat order #{{ $history->id }} · {{ $history->outcome }}</p>
<p>Dispatch {{ $history->dispatch_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} · scan {{ $history->observed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} · jendela {{ $history->history_timezone }}</p>
<p>{{ $history->row_count }} baris dari batas {{ $history->requested_limit }} · rentang {{ $history->coverage_start?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? 'riwayat kosong' }} s/d {{ $history->coverage_end?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '—' }} · {{ $history->error_code ?? 'tanpa error' }}</p>
@if($history->candidate_order_ids)<p class="break-all">Order kandidat (perlu GET exact dan cocokkan jumlah/biaya): {{ implode(', ', $history->candidate_order_ids) }}</p>@endif
</div>
@endif
<details class="mt-3"><summary>Tindakan aman (tidak boleh menghapus ambiguitas)</summary>
<form method="POST" action="{{ route('admin.suppliers.operate', $fulfillment) }}" class="mt-3 grid gap-3">
@csrf
<input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id }}">
<label>Tindakan <select class="w-full rounded border p-2" name="action">
@if($fulfillment->state !== 'delivered')
<option value="retry">Coba setelah isi saldo (hanya belum terkirim / terbukti ditolak)</option>
@endif
<option value="recover">Pulihkan dengan ID supplier order yang terverifikasi (GET)</option>
@if($fulfillment->currentPurchase && in_array($fulfillment->currentPurchase->state, ['ambiguous', 'review'], true) && $fulfillment->currentPurchase->supplier_order_id === null && $fulfillment->currentPurchase->supplier?->provider === 'toolorax')
<option value="history">Baca riwayat order ToolOraX (GET, maks. 100)</option>
@endif
@if($fulfillment->currentPurchase && in_array($fulfillment->currentPurchase->state, ['ambiguous', 'review'], true) && $fulfillment->currentPurchase->supplier_order_id === null)
<option value="debit_confirmed">Konfirmasi debit dengan order ID, jumlah dan biaya</option>
<option value="no_debit">Konfirmasi no-debit dengan bukti provider</option>
@endif
@if($fulfillment->state !== 'delivered')
<option value="manual">Kredensial manual (kewajiban pembelian asli tetap harus diselesaikan)</option>
@endif
</select></label>
<label>ID supplier order terverifikasi <input class="w-full rounded border p-2" name="supplier_order_id" maxlength="128" autocomplete="off"></label>
<label>Jumlah pada bukti debit <input class="w-full rounded border p-2" name="debit_quantity" type="number" min="1" max="10000"></label>
<label>Biaya aktual pada bukti debit (micros mata uang supplier) <input class="w-full rounded border p-2" name="debit_cost_micros" type="number" min="1" step="1"></label>
<label>Sumber bukti no-debit <select class="w-full rounded border p-2" name="proof_source"><option value="provider_case">Konfirmasi kasus dari provider</option><option value="provider_statement">Pernyataan / riwayat provider</option>@if($fulfillment->currentPurchase?->latestHistoryReconciliation?->outcome === \App\Models\SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER)<option value="provider_history">Hasil rekonsiliasi riwayat order #{{ $fulfillment->currentPurchase->latestHistoryReconciliation->id }}</option>@endif</select></label>
<label>Referensi bukti provider <input class="w-full rounded border p-2" name="proof_reference" maxlength="255" autocomplete="off" placeholder="ID case / dokumen provider"></label>
<label>ID hasil history no-order (khusus bukti provider_history) <input class="w-full rounded border p-2" name="history_reconciliation_id" type="number" min="1" value="{{ $fulfillment->currentPurchase?->latestHistoryReconciliation?->outcome === \App\Models\SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER ? $fulfillment->currentPurchase->latestHistoryReconciliation->id : '' }}"></label>
<label>Timezone timestamp provider (IANA, hanya saat baca history) <input class="w-full rounded border p-2" name="history_timezone" maxlength="64" placeholder="Contoh: Asia/Jakarta" autocomplete="off"><small>Masukkan timezone timestamp ShopBot yang sudah dikonfirmasi dari provider. Timestamp tanpa offset yang timezone-nya tidak diketahui tidak membuktikan coverage.</small></label>
<label>Catatan verifikasi / bukti <textarea class="w-full rounded border p-2" name="proof_note" maxlength="4000"></textarea></label>
@if($fulfillment->state !== 'delivered')
<label>Kredensial manual, satu unit per baris (tidak disimpan dalam session) <textarea class="w-full rounded border p-2" name="credentials" maxlength="64000" autocomplete="off" spellcheck="false"></textarea></label>
@endif
<button class="rounded border-2 px-3 py-2 font-bold">Jalankan tindakan fulfillment</button>
</form></details></article>
@endforeach
{{ $fulfillments->links() }}
</section>
<section class="grid gap-3"><h2 class="font-bold">Riwayat pembelian (read-only)</h2>
@foreach($purchases as $purchase)<article class="rounded-2xl border-2 bg-white p-4"><h3 class="font-bold">Pembelian #{{ $purchase->id }} · {{ $purchase->state }}</h3><p class="break-words">Fulfillment #{{ $purchase->supplier_fulfillment_id }} · Supplier #{{ $purchase->supplier_id }} · Referensi {{ $purchase->reference }} · Supplier order {{ $purchase->supplier_order_id ?? 'Belum diketahui' }}</p><p>{{ $purchase->quantity }} unit · Estimasi micro-unit {{ $purchase->expected_cost_micros }} · Aktual {{ $purchase->actual_cost_micros ?? 'Belum terbukti' }} · {{ $purchase->updated_at }}</p></article>@endforeach
{{ $purchases->links() }}
</section>
@endsection
