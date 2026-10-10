@extends('admin.suppliers.layout')
@section('title', 'Supplier & harga jual · '.$product->name)
@section('content')
<x-admin-dashboard.card padding="compact" shadow="sm" aria-labelledby="supplier-product-pricing-title">
    <p class="text-xs font-medium text-admin-muted">Produk toko #{{ $product->id }}</p>
    <h2 id="supplier-product-pricing-title" class="mt-1 break-words text-2xl font-semibold tracking-tight text-admin-ink">Supplier &amp; harga jual</h2>
    <p class="mt-2 text-xl font-semibold text-admin-ink">Rp{{ number_format($product->price_amount, 0, ',', '.') }}</p>
    <p class="mt-1 text-sm leading-6 text-admin-muted">Pilih supplier tempat membeli produk dan tentukan cara mengatur harga jual. Harga pesanan yang sudah dibuat tetap sama.</p>
</x-admin-dashboard.card>
@foreach($sources as $source)
@php($item = $source->catalogItem)
@php($supplier = $item->supplier)
@php($rank = $loop->iteration)
@php($cost = $sourceCosts[$source->id] ?? null)
<x-admin-dashboard.card as="article" padding="compact" shadow="sm">
<div class="flex flex-wrap items-start justify-between gap-3">
    <div><p class="text-xs font-medium text-admin-muted">Sumber stok · {{ $supplier->name }}</p><h2 class="mt-1 break-words text-lg font-semibold text-admin-ink">{{ $item->name }}</h2><p class="mt-1 font-mono text-xs break-all text-admin-muted">SKU {{ $item->external_id }}</p>
    <p class="mt-2 text-sm text-admin-muted"><span class="font-medium">Harga beli/unit</span>
    @if(($cost['idr'] ?? null) !== null)
        <strong class="text-base font-semibold text-admin-ink">Rp{{ number_format($cost['idr'], 0, ',', '.') }}</strong> <span class="text-xs text-admin-muted">({{ $cost['unit'] }} {{ $supplier->currency }} · kurs Rp{{ number_format($supplier->exchange_rate_idr, 0, ',', '.') }} · katalog {{ $item->synced_at?->format('d/m H:i') ?? 'belum sync' }})</span>
    @else
        <strong class="font-semibold text-neo-warning-text">Belum ada harga — sinkronkan katalog supplier.</strong>
    @endif
    </p>
    </div>
    <x-admin-dashboard.badge :tone="$rank === 1 ? 'emerald' : 'slate'">Urutan {{ $rank }}{{ $rank === 1 ? ' · utama' : ' · cadangan' }}</x-admin-dashboard.badge>
</div>
@if($sources->count() > 1)
<form method="POST" action="{{ route('admin.suppliers.move', $item) }}" class="mt-4 flex flex-wrap items-center gap-2">
    @csrf
    <input type="hidden" name="product_id" value="{{ $product->id }}">
    <input type="hidden" name="version" value="{{ $source->version }}">
    <input type="hidden" name="supplier_version" value="{{ $supplier->version }}">
    <x-admin-dashboard.button type="submit" name="direction" value="up" variant="neutral" size="sm" :disabled="$loop->first">↑ Naikkan</x-admin-dashboard.button>
    <x-admin-dashboard.button type="submit" name="direction" value="down" variant="neutral" size="sm" :disabled="$loop->last">↓ Turunkan</x-admin-dashboard.button>
    <small class="text-sm text-admin-muted">Urutan 1 dicoba lebih dulu saat pembelian; urutan berikutnya menjadi cadangan.</small>
</form>
@else
<p class="mt-3 text-sm text-admin-muted">Baru satu supplier untuk produk ini. Petakan supplier kedua dari Katalog privat untuk mengatur urutan utama/cadangan.</p>
@endif
<form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="mt-4 grid gap-4">
    @csrf
    <input type="hidden" name="supplier_version" value="{{ $supplier->version }}">
    <input type="hidden" name="version" value="{{ $source->version }}">
    <input type="hidden" name="product_id" value="{{ $product->id }}">
    <input type="hidden" name="priority" value="{{ $source->priority }}">
    <input type="hidden" name="is_active" value="0">
    <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink"><input type="checkbox" name="is_active" value="1" class="h-5 w-5 rounded border-admin-line accent-admin-accent" @checked($source->is_active) @disabled($item->requires_input || !$item->orderable)> Gunakan supplier ini untuk membeli produk</label>
    <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink"><input type="checkbox" name="equivalent_confirmed" value="1" required class="h-5 w-5 rounded border-admin-line accent-admin-accent"> Saya memastikan paket, durasi, dan garansinya sesuai produk toko.</label>
    <x-admin-dashboard.button type="submit" variant="primary" size="sm">Simpan pilihan supplier</x-admin-dashboard.button>
</form>
<form method="POST" action="{{ route('admin.suppliers.detach', $item) }}" class="mt-4"
    data-detach-confirmation="Lepas mapping koneksi {{ $supplier->name }} / SKU {{ $item->external_id }} dari produk {{ $product->name }}? Mapping yang sudah digunakan dalam riwayat pemenuhan/pembelian tidak boleh dilepas; nonaktifkan saja. Stok lokal, harga, dan riwayat tetap tersimpan."
    onsubmit="return confirm(this.dataset.detachConfirmation)">
    @csrf
    @method('DELETE')
    <input type="hidden" name="product_id" value="{{ $product->id }}">
    <input type="hidden" name="source_id" value="{{ $source->id }}">
    <input type="hidden" name="version" value="{{ $source->version }}">
    <input type="hidden" name="supplier_version" value="{{ $supplier->version }}">
    <x-admin-dashboard.button type="submit" variant="destructive" size="sm">Lepas mapping</x-admin-dashboard.button>
    <p class="mt-2 text-sm text-admin-muted">Lepas hanya mapping yang belum digunakan. Jika ada riwayat pemenuhan/pembelian, nonaktifkan pilihan supplier di atas agar pemulihan tetap aman.</p>
</form>
@if($item->requires_input || !$item->orderable)<x-admin-dashboard.notice tone="warning" class="mt-4">Produk supplier ini belum mendukung pembelian dan pengiriman otomatis.</x-admin-dashboard.notice>@endif
</x-admin-dashboard.card>
@endforeach
<x-admin-dashboard.card as="section" padding="compact" shadow="sm"><p class="text-xs font-medium text-admin-muted">Kebijakan produk</p><h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Pengaturan harga jual</h2>
<div class="mt-3 space-y-2 text-sm leading-6 text-admin-muted">
    <p class="font-semibold text-admin-ink">Jika mode otomatis disimpan</p>
    <p>Harga otomatis tersimpan dihitung dari supplier aktif dan layak pertama menurut urutan (Urutan 1 diprioritaskan). Jika Urutan 1 tidak memenuhi syarat basis harga, source berikutnya yang memenuhi syarat digunakan.</p>
    <p>Kelayakan basis harga mengikuti filter harga saat ini: mapping dan koneksi aktif, currency sesuai, mendukung pembelian tanpa input tambahan, observasi masih segar, serta harga valid. Bukan memilih harga termurah, dan kelayakan basis harga bukan jaminan stok atau saldo cukup saat checkout.</p>
    @if(!isset($pricePreview) && ($policy?->mode ?? 'manual') === 'manual')
        <p>Harga otomatis tidak aktif pada mode manual. Harga jual tersimpan mengikuti harga yang kamu isi di halaman Produk.</p>
    @endif
    @if($sources->isEmpty())
        <p>Belum ada mapping supplier; belum ada source untuk menghitung harga otomatis atau simulasi SKU.</p>
    @endif
</div>
@isset($pricePreview)
<div id="price-preview" class="my-4 rounded-lg border border-admin-line bg-admin-surface-muted p-3" role="status">
    <h3 class="text-lg font-semibold text-admin-ink">Hasil simulasi harga untuk 1 produk</h3>
    <p class="mt-1 text-sm text-admin-muted">{{ $previewItem->name }} · Berdasarkan harga supplier yang terakhir diperbarui{{ $previewItem->synced_at ? ' pada '.$previewItem->synced_at->format('d/m/Y H:i') : '' }}.</p>
    @if($pricePreview['cost_idr'] === null || $pricePreview['retail_idr'] === null)
        <p class="mt-3 text-sm text-admin-muted">Harga beli supplier belum tersedia. Perbarui katalog supplier lalu coba lagi.</p>
    @else
        <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><dt class="text-xs font-medium text-admin-muted">Harga beli dari supplier</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">Rp{{ number_format($pricePreview['cost_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-xs font-medium text-admin-muted">Harga jual ke pembeli</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">Rp{{ number_format($pricePreview['retail_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-xs font-medium text-admin-muted">Selisih harga jual dan harga beli</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">Rp{{ number_format($pricePreview['retail_idr'] - $pricePreview['cost_idr'], 0, ',', '.') }}</dd></div>
            <div><dt class="text-xs font-medium text-admin-muted">Sisa setelah cadangan biaya</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">Rp{{ number_format($pricePreview['retail_idr'] - $pricePreview['cost_idr'] - $policy->buffer_idr, 0, ',', '.') }}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-admin-muted">Cadangan biaya: Rp{{ number_format($policy->buffer_idr, 0, ',', '.') }} · Keuntungan minimal yang kamu pilih: Rp{{ number_format($policy->minimum_profit_idr, 0, ',', '.') }}.</p>
    @endif
    <p class="mt-3 text-sm text-admin-muted">Simulasi SKU terpilih tidak mengubah urutan supplier atau source basis harga otomatis tersimpan. Belum membeli produk atau menyimpan perubahan. Klik “Simpan pengaturan harga” di bawah untuk menyimpan kebijakan, bukan untuk memilih SKU basis harga tersimpan.</p>
</div>
@endisset
<form method="POST" action="{{ route('admin.products.supplier-pricing', $product) }}" class="mt-3 grid gap-3 sm:grid-cols-2" x-data="{ mode: @js($policy?->mode ?? 'manual') }">@csrf<input type="hidden" name="version" value="{{ $policy?->version ?? 0 }}">
<label class="grid gap-2 text-sm font-medium text-admin-ink">Cara menentukan harga jual<x-admin-dashboard.select name="mode" x-model="mode">@foreach(['manual' => 'Saya tentukan sendiri', 'percent' => 'Otomatis: tambah persentase dari modal', 'fixed' => 'Otomatis: tambah keuntungan rupiah', 'hybrid' => 'Otomatis: persentase dengan keuntungan minimum'] as $value => $label)<option value="{{ $value }}" @selected(($policy?->mode ?? 'manual') === $value)>{{ $label }}</option>@endforeach</x-admin-dashboard.select><small class="mt-1 block text-sm text-admin-muted" x-show="mode === 'manual'">Harga jual mengikuti harga yang kamu isi di halaman Produk.</small></label>
<input type="hidden" name="rounding_idr" value="{{ $policy?->rounding_idr ?? 1 }}" x-bind:disabled="mode !== 'manual'">
<label class="grid gap-2 text-sm font-medium text-admin-ink" x-show="mode !== 'manual'" x-cloak>Bulatkan harga jual ke atas<x-admin-dashboard.select name="rounding_idr" x-bind:disabled="mode === 'manual'">@foreach([1,100,500,1000] as $rounding)<option value="{{ $rounding }}" @selected(($policy?->rounding_idr ?? 1) === $rounding)>{{ $rounding === 1 ? 'Tanpa pembulatan tambahan' : 'Kelipatan Rp'.number_format($rounding, 0, ',', '.') }}</option>@endforeach</x-admin-dashboard.select></label>
@foreach(['markup_basis_points' => 'Tambahan harga berdasarkan persentase', 'profit_idr' => 'Keuntungan untuk menghitung harga jual (Rp/produk)', 'minimum_profit_idr' => 'Keuntungan minimal yang ingin dijaga (Rp/produk)', 'buffer_idr' => 'Cadangan biaya tambahan (Rp/produk)'] as $field => $label)
@php($visible = match ($field) { 'markup_basis_points' => "['percent', 'hybrid'].includes(mode)", 'profit_idr' => "['fixed', 'hybrid'].includes(mode)", default => 'true' })
@if($visible !== 'true')<input type="hidden" name="{{ $field }}" value="{{ $policy?->$field ?? 0 }}" x-bind:disabled="{{ $visible }}">@endif
<label class="grid gap-2 text-sm font-medium text-admin-ink" x-show="{{ $visible }}" @if($visible !== 'true') x-cloak @endif>{{ $label }}<x-admin-dashboard.input name="{{ $field }}" type="number" min="0" max="{{ $field === 'markup_basis_points' ? 100000 : 1000000000 }}" required value="{{ $policy?->$field ?? 0 }}" x-bind:disabled="!({{ $visible }})" />
<small class="mt-1 block text-sm text-admin-muted">{{ match ($field) {
    'markup_basis_points' => 'Isi 100 untuk tambahan 1%, atau 1000 untuk tambahan 10% dari harga beli supplier.',
    'profit_idr' => 'Contoh: isi 5000 untuk menambahkan Rp5.000 ke harga beli. Pada mode gabungan, sistem memilih tambahan yang lebih besar antara persentase dan angka ini.',
    'minimum_profit_idr' => 'Supplier tidak dipakai jika modal yang terbaca membuat sisa keuntungan di bawah angka ini, setelah cadangan biaya diperhitungkan.',
    'buffer_idr' => 'Cadangan untuk biaya isi saldo, selisih kurs, atau biaya lain. Bukan uang yang otomatis dipotong. Isi 0 jika tidak diperlukan.',
} }}</small></label>
@endforeach
@if($sources->isNotEmpty())<label class="grid gap-2 text-sm font-medium text-admin-ink">Hitung simulasi berdasarkan SKU<x-admin-dashboard.select name="catalog_item_id">@foreach($sources as $source)<option value="{{ $source->supplier_catalog_item_id }}" @selected(isset($previewItem) && $previewItem->id === $source->supplier_catalog_item_id)>{{ $source->catalogItem->name }}</option>@endforeach</x-admin-dashboard.select><small class="mt-1 block text-sm text-admin-muted">SKU yang dipilih hanya untuk simulasi; tidak memilih source harga tersimpan dan tidak menyimpan kebijakan atau harga jual.</small></label><x-admin-dashboard.button type="submit" variant="neutral" size="sm" formaction="{{ route('admin.products.supplier-pricing.preview', $product) }}#price-preview" class="self-end">Lihat perkiraan modal & harga jual</x-admin-dashboard.button>@endif
<x-admin-dashboard.button type="submit" variant="primary" class="sm:col-span-2">Simpan pengaturan harga</x-admin-dashboard.button></form></x-admin-dashboard.card>
@endsection
