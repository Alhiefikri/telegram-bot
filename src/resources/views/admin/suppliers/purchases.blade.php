@extends('admin.suppliers.layout')
@section('title', 'Monitor pembelian supplier')
@section('content')
<p>Metadata privat saja: tidak ada API key, payload mentah, atau kredensial pembeli. Ambiguitas tidak mengizinkan pembelian ulang, fallback, atau refund. Tindakan memerlukan konfirmasi password dalam 15 menit dan tetap diperiksa oleh layanan fulfillment.</p>
<section class="grid gap-3"><h2 class="font-bold">Fulfillment / komitmen belum terselesaikan</h2>
@foreach($fulfillments as $fulfillment)
<article class="rounded-2xl border-2 bg-white p-4"><h3 class="font-bold">Fulfillment #{{ $fulfillment->id }} · {{ $fulfillment->state }}</h3><p class="break-words">Pesanan {{ $fulfillment->order_id }} · Item #{{ $fulfillment->order_item_id }} · {{ $fulfillment->quantity }} unit</p><a class="underline" href="{{ route('admin.orders.show', $fulfillment->order_id) }}">Pesanan / refund melalui alur keuangan yang ada</a>
@if($fulfillment->state === 'delivered')
<p class="mt-3">Delivery customer sudah selesai. Pulihkan hanya bukti pembelian asli; jangan membeli atau mengirim kredensial pengganti lagi.</p>
@endif
<details class="mt-3"><summary>Tindakan aman (tidak boleh menghapus ambiguitas)</summary>
<form method="POST" action="{{ route('admin.suppliers.operate', $fulfillment) }}" class="mt-3 grid gap-3">
@csrf
<label>Tindakan <select class="w-full rounded border p-2" name="action">
@if($fulfillment->state !== 'delivered')
<option value="retry">Coba setelah isi saldo (hanya belum terkirim / terbukti ditolak)</option>
@endif
<option value="recover">Pulihkan dengan ID supplier order yang terverifikasi (GET)</option>
@if($fulfillment->state !== 'delivered')
<option value="manual">Kredensial manual (kewajiban pembelian asli tetap harus diselesaikan)</option>
@endif
</select></label>
<label>ID supplier order terverifikasi <input class="w-full rounded border p-2" name="supplier_order_id" maxlength="128" autocomplete="off"></label>
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
