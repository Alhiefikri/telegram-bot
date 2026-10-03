@extends('admin.suppliers.layout')
@section('title', 'Supplier & harga jual · '.$product->name)
@section('content')
<p>Produk #{{ $product->id }} · harga jual Rp {{ number_format($product->price_amount, 0, ',', '.') }}. Pilih supplier tempat membeli produk dan tentukan cara mengatur harga jual. Harga pesanan yang sudah dibuat tetap sama.</p>
@foreach($sources as $source)
@php($item = $source->catalogItem)
@php($supplier = $item->supplier)
<article class="rounded-2xl border-2 bg-white p-4"><h2 class="font-bold">{{ $supplier->name }} · {{ $item->name }} · SKU {{ $item->external_id }}</h2>
<form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="mt-3 grid gap-3">@csrf<input type="hidden" name="supplier_version" value="{{ $supplier->version }}"><input type="hidden" name="version" value="{{ $source->version }}"><input type="hidden" name="product_id" value="{{ $product->id }}"><label>Urutan supplier <input class="rounded border p-2" name="priority" type="number" min="0" max="10000" value="{{ $source->priority }}" required><small class="mt-1 block text-sm">Angka lebih kecil didahulukan. Contoh: 10 menjadi pilihan utama, 20 menjadi cadangan.</small></label><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked($source->is_active) @disabled($item->requires_input || !$item->orderable)> Gunakan supplier ini untuk membeli produk</label><label><input type="checkbox" name="equivalent_confirmed" value="1" required> Saya memastikan paket, durasi, dan garansinya sesuai produk toko.</label><button class="rounded border-2 px-3 py-2 font-bold">Simpan pilihan supplier</button></form>@if($item->requires_input || !$item->orderable)<p class="mt-2 text-sm">Produk supplier ini belum mendukung pembelian dan pengiriman otomatis.</p>@endif</article>
@endforeach
<section class="rounded-2xl border-2 bg-white p-4"><h2 class="font-bold">Pengaturan harga jual</h2>
@isset($pricePreview)
<div id="price-preview" class="my-4 rounded-xl border-2 bg-neo-paper p-4" role="status">
    <h3 class="text-lg font-bold">Hasil cek harga untuk 1 produk</h3>
    <p class="mt-1 text-sm">{{ $previewItem->name }} · Berdasarkan harga supplier yang terakhir diperbarui{{ $previewItem->synced_at ? ' pada '.$previewItem->synced_at->format('d/m/Y H:i') : '' }}.</p>
    @if($pricePreview['cost_idr'] === null || $pricePreview['retail_idr'] === null)
        <p class="mt-3">Harga beli supplier belum tersedia. Perbarui katalog supplier lalu coba lagi.</p>
    @else
        <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><dt class="text-sm">Harga beli dari supplier</dt><dd class="text-xl font-bold">Rp{{ number_format($pricePreview['cost_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-sm">Harga jual ke pembeli</dt><dd class="text-xl font-bold">Rp{{ number_format($pricePreview['retail_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-sm">Selisih harga jual dan harga beli</dt><dd class="text-xl font-bold">Rp{{ number_format($pricePreview['retail_idr'] - $pricePreview['cost_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-sm">Sisa setelah cadangan biaya</dt><dd class="text-xl font-bold">Rp{{ number_format($pricePreview['retail_idr'] - $pricePreview['cost_idr'] - $policy->buffer_idr, 0, ',', '.') }}</dd></div>
        </dl>
        <p class="mt-3 text-sm">Cadangan biaya: Rp{{ number_format($policy->buffer_idr, 0, ',', '.') }} · Keuntungan minimal yang kamu pilih: Rp{{ number_format($policy->minimum_profit_idr, 0, ',', '.') }}.</p>
    @endif
    <p class="mt-3 text-sm">Ini hanya pengecekan. Belum membeli produk atau menyimpan perubahan. Klik “Simpan pengaturan harga” di bawah jika sudah sesuai.</p>
</div>
@endisset
<form method="POST" action="{{ route('admin.products.supplier-pricing', $product) }}" class="mt-3 grid gap-3 sm:grid-cols-2" x-data="{ mode: @js($policy?->mode ?? 'manual') }">@csrf<input type="hidden" name="version" value="{{ $policy?->version ?? 0 }}">
<label>Cara menentukan harga jual <select class="w-full rounded border p-2" name="mode" x-model="mode">@foreach(['manual' => 'Saya tentukan sendiri', 'percent' => 'Otomatis: tambah persentase dari modal', 'fixed' => 'Otomatis: tambah keuntungan rupiah', 'hybrid' => 'Otomatis: persentase dengan keuntungan minimum'] as $value => $label)<option value="{{ $value }}" @selected(($policy?->mode ?? 'manual') === $value)>{{ $label }}</option>@endforeach</select><small class="mt-1 block text-sm" x-show="mode === 'manual'">Harga jual mengikuti harga yang kamu isi di halaman Produk.</small></label>
<input type="hidden" name="rounding_idr" value="{{ $policy?->rounding_idr ?? 1 }}" x-bind:disabled="mode !== 'manual'">
<label x-show="mode !== 'manual'" x-cloak>Bulatkan harga jual ke atas <select class="w-full rounded border p-2" name="rounding_idr" x-bind:disabled="mode === 'manual'">@foreach([1,100,500,1000] as $rounding)<option value="{{ $rounding }}" @selected(($policy?->rounding_idr ?? 1) === $rounding)>{{ $rounding === 1 ? 'Tanpa pembulatan tambahan' : 'Kelipatan Rp'.number_format($rounding, 0, ',', '.') }}</option>@endforeach</select></label>
@foreach(['markup_basis_points' => 'Tambahan harga berdasarkan persentase', 'profit_idr' => 'Keuntungan untuk menghitung harga jual (Rp/produk)', 'minimum_profit_idr' => 'Keuntungan minimal yang ingin dijaga (Rp/produk)', 'buffer_idr' => 'Cadangan biaya tambahan (Rp/produk)'] as $field => $label)
@php($visible = match ($field) { 'markup_basis_points' => "['percent', 'hybrid'].includes(mode)", 'profit_idr' => "['fixed', 'hybrid'].includes(mode)", default => 'true' })
@if($visible !== 'true')<input type="hidden" name="{{ $field }}" value="{{ $policy?->$field ?? 0 }}" x-bind:disabled="{{ $visible }}">@endif
<label x-show="{{ $visible }}" @if($visible !== 'true') x-cloak @endif>{{ $label }} <input class="w-full rounded border p-2" name="{{ $field }}" type="number" min="0" max="{{ $field === 'markup_basis_points' ? 100000 : 1000000000 }}" required value="{{ $policy?->$field ?? 0 }}" x-bind:disabled="!({{ $visible }})">
<small class="mt-1 block text-sm">{{ match ($field) {
    'markup_basis_points' => 'Isi 100 untuk tambahan 1%, atau 1000 untuk tambahan 10% dari harga beli supplier.',
    'profit_idr' => 'Contoh: isi 5000 untuk menambahkan Rp5.000 ke harga beli. Pada mode gabungan, sistem memilih tambahan yang lebih besar antara persentase dan angka ini.',
    'minimum_profit_idr' => 'Supplier tidak dipakai jika modal yang terbaca membuat sisa keuntungan di bawah angka ini, setelah cadangan biaya diperhitungkan.',
    'buffer_idr' => 'Cadangan untuk biaya isi saldo, selisih kurs, atau biaya lain. Bukan uang yang otomatis dipotong. Isi 0 jika tidak diperlukan.',
} }}</small></label>
@endforeach
@if($sources->isNotEmpty())<label>Produk supplier yang ingin dicek <select class="w-full rounded border p-2" name="catalog_item_id">@foreach($sources as $source)<option value="{{ $source->supplier_catalog_item_id }}" @selected(isset($previewItem) && $previewItem->id === $source->supplier_catalog_item_id)>{{ $source->catalogItem->name }}</option>@endforeach</select></label><button type="submit" formaction="{{ route('admin.products.supplier-pricing.preview', $product) }}#price-preview" class="rounded border px-3 py-2 font-bold">Lihat perkiraan modal & harga jual</button>@endif
<button type="submit" class="rounded border-2 px-3 py-2 font-bold sm:col-span-2">Simpan pengaturan harga</button></form></section>
@endsection
