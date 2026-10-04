@extends('admin.suppliers.layout')
@section('title', 'Katalog privat · '.$supplier['name'])
@section('content')
<section class="supplier-panel grid gap-4 p-5 sm:grid-cols-3" aria-label="Informasi katalog">
    <div><p class="supplier-eyebrow">Provider</p><p class="mt-2 font-black">{{ \App\Enums\SupplierProvider::from($supplier['provider'])->label() }}</p></div>
    <div><p class="supplier-eyebrow">Konversi biaya</p><p class="mt-2 font-black">Rp{{ number_format($supplier['exchange_rate_idr'], 0, ',', '.') }} / {{ $supplier['currency'] }}</p></div>
    <div><p class="supplier-eyebrow">Katalog supplier</p><p class="mt-2 font-black">{{ $items->total() }} produk · Versi {{ $supplier['version'] }}</p></div>
    <p class="supplier-muted text-sm leading-relaxed sm:col-span-3">Deskripsi supplier hanya referensi privat, tidak pernah dipublikasikan otomatis. Impor sebagai draft produk toko atau hubungkan ke produk yang sudah ada.</p>
</section>
<div class="grid min-w-0 items-start gap-5 lg:grid-cols-2">
@forelse($items as $item)
<article class="supplier-panel min-w-0 overflow-hidden">
    <header class="grid gap-3 border-b-2 border-neo-ink p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><p class="supplier-eyebrow">Produk #{{ $item->id }}</p><span class="supplier-badge {{ !$item->requires_input && $item->orderable ? 'bg-neo-success-soft text-neo-success-text' : 'bg-neo-warning-soft text-neo-warning-text' }}">{{ $item->requires_input ? 'Aktivasi manual' : ($item->orderable ? 'Pengiriman instan' : 'Tidak dapat dipesan') }}</span></div>
        <h2 class="break-words text-lg font-black">{{ $item->name }}</h2>
        <p class="supplier-muted break-all text-xs">SKU {{ $item->external_id }}</p>
    </header>
    <div class="grid gap-4 p-5">
        <dl class="grid grid-cols-2 gap-3">
            <div class="supplier-metric col-span-2 bg-neo-info-soft"><dt class="text-xs font-bold">Harga beli · {{ $supplier['currency'] }}</dt><dd class="mt-1 text-2xl font-black">{{ $item->unit_price_micros === null ? 'Tidak diketahui' : \App\Services\Suppliers\SupplierMoney::decimal($item->unit_price_micros) }}</dd></div>
            <div class="supplier-metric"><dt class="supplier-muted text-xs font-bold">Stok supplier</dt><dd class="mt-1 text-lg font-black">{{ $item->stock ?? 'Tidak diketahui' }}</dd></div>
            <div class="supplier-metric"><dt class="supplier-muted text-xs font-bold">Ditahan</dt><dd class="mt-1 text-lg font-black">{{ $item->reserved_quantity }}</dd></div>
        </dl>
        @if($item->requires_input)<p class="supplier-alert">Input/aktivasi manual: tidak bisa diaktifkan untuk pengiriman instan.</p>@endif
        @if($item->description)<details class="supplier-disclosure rounded-xl border border-neo-ink/15 p-3"><summary class="text-sm font-bold">Deskripsi supplier privat <span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary><p class="supplier-muted mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed">{{ $item->description }}</p></details>@endif
        <div><h3 class="supplier-eyebrow">Produk toko terhubung</h3><div class="mt-3 flex flex-wrap gap-2">@forelse($item->sources as $source)<a class="supplier-button text-sm" href="{{ route('admin.products.suppliers', $source->product_id) }}">#{{ $source->product_id }} {{ $source->product?->name ?? 'Produk dihapus' }} <span aria-hidden="true">→</span></a>@empty<p class="supplier-muted text-sm">Belum dipetakan ke produk toko.</p>@endforelse</div></div>
    </div>
    <div class="grid gap-3 border-t-2 border-neo-ink p-5">
        <details class="supplier-disclosure rounded-xl border-2 border-neo-ink">
            <summary class="flex items-center justify-between gap-3 bg-neo-yellow p-3 text-sm font-bold">Impor sebagai draft <span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary>
            <div class="p-4"><p class="supplier-muted mb-4 text-xs leading-relaxed">Draft selalu nonaktif. Pengulangan impor tidak membuat duplikat.</p>
                <form method="POST" action="{{ route('admin.suppliers.import', $item) }}" class="grid gap-4">
                    @csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}">
                    <label>Nama produk toko <input name="name" required maxlength="255"></label>
                    <label>Deskripsi draft toko <textarea name="description" maxlength="10000" rows="3"></textarea></label>
                    <label>Harga jual ke pembeli (Rp) <input name="price_amount" type="number" min="1" max="1000000000" required></label>
                    <button class="supplier-button supplier-button-primary">Impor draft nonaktif</button>
                </form>
            </div>
        </details>
        <details class="supplier-disclosure rounded-xl border-2 border-neo-ink">
            <summary class="flex items-center justify-between gap-3 p-3 text-sm font-bold">Hubungkan ke produk toko yang sudah ada <span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary>
            @php($availableProducts = $products->whereNotIn('id', $item->sources->pluck('product_id')))
            <form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="grid gap-4 p-4">
                @csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}"><input type="hidden" name="version" value="0">
                <label>Produk toko
                    <select name="product_id" required @disabled($availableProducts->isEmpty())>
                        <option value="" selected disabled>Pilih produk yang ingin dihubungkan</option>
                        @foreach($availableProducts as $storeProduct)
                            <option value="{{ $storeProduct->id }}">{{ $storeProduct->name }} · Rp{{ number_format($storeProduct->price_amount, 0, ',', '.') }} · {{ $storeProduct->is_active ? 'Aktif' : 'Draft / nonaktif' }} · #{{ $storeProduct->id }}</option>
                        @endforeach
                    </select>
                </label>
                @if($availableProducts->isEmpty())
                    <p class="supplier-muted text-sm">Tidak ada produk yang bisa dihubungkan. Semua produk toko sudah terhubung ke SKU ini, atau belum ada produk toko. Impor draft di atas untuk membuat produk baru; gunakan tombol produk terhubung untuk mengedit pemetaan yang sudah ada.</p>
                @else
                    <p class="supplier-muted text-xs">Satu produk toko dapat dihubungkan ke beberapa supplier. Produk yang sudah terhubung ke SKU ini tidak ditampilkan lagi.</p>
                @endif
                <label>Urutan supplier (angka lebih kecil didahulukan) <input name="priority" type="number" min="0" max="10000" value="100" required></label>
                <input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @disabled($item->requires_input || !$item->orderable)> Aktif</label>
                <label><input type="checkbox" name="equivalent_confirmed" value="1" required> Saya memastikan paket, durasi, dan garansinya sesuai produk toko.</label>
                <button class="supplier-button supplier-button-primary" @disabled($availableProducts->isEmpty())>Simpan hubungan produk</button>
            </form>
        </details>
    </div>
</article>
@empty
    <div class="supplier-panel p-8 text-center lg:col-span-2"><h2 class="text-lg font-black">Katalog masih kosong</h2><p class="supplier-muted mt-2 text-sm">Kembali ke Supplier dashboard lalu sinkronkan katalog dan saldo untuk membaca produk supplier.</p><a class="supplier-button mt-4" href="{{ route('admin.dashboard', ['tab' => 'suppliers']) }}">Kembali ke dashboard <span aria-hidden="true">→</span></a></div>
@endforelse
</div>
{{ $items->links() }}
@endsection
