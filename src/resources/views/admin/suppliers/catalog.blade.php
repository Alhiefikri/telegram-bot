@extends('admin.suppliers.layout')
@section('title', 'Katalog privat · '.$supplier['name'])
@section('content')
<x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="supplier-catalog-title">
    <p class="text-xs font-medium text-admin-muted">Katalog privat</p>
    <h2 id="supplier-catalog-title" class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">{{ $supplier['name'] }}</h2>
    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
        <div><dt class="text-xs font-medium text-admin-muted">Provider</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">{{ \App\Enums\SupplierProvider::from($supplier['provider'])->label() }}</dd></div>
        <div><dt class="text-xs font-medium text-admin-muted">Konversi biaya</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">Rp{{ rtrim(rtrim(number_format((float) $supplier['exchange_rate_idr'], 6, ',', '.'), '0'), ',') }} / {{ $supplier['currency'] }}@if($supplier['usd_rate_idr'] !== null && ! in_array($supplier['currency'], ['USD', 'USDT'], true)) · Rp{{ rtrim(rtrim(number_format((float) $supplier['usd_rate_idr'], 6, ',', '.'), '0'), ',') }} / USD @endif</dd></div>
        <div><dt class="text-xs font-medium text-admin-muted">Katalog supplier</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">{{ $items->total() }} produk · Versi {{ $supplier['version'] }}</dd></div>
    </dl>
    <p class="mt-3 text-sm leading-6 text-admin-muted">Deskripsi supplier hanya referensi privat, tidak pernah dipublikasikan otomatis. Impor sebagai draft produk toko atau hubungkan ke produk yang sudah ada.</p>
</x-admin-dashboard.card>
<div class="grid min-w-0 items-start gap-5 lg:grid-cols-2">
@forelse($items as $item)
<x-admin-dashboard.card as="article" padding="none" shadow="sm" class="min-w-0 overflow-hidden">
    <header class="grid gap-3 border-b border-admin-line p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-xs font-medium text-admin-muted">Produk #{{ $item->id }}</p><x-admin-dashboard.badge :tone="!$item->requires_input && $item->orderable ? 'emerald' : 'amber'">{{ $item->requires_input ? 'Aktivasi manual' : ($item->orderable ? 'Pengiriman instan' : 'Tidak dapat dipesan') }}</x-admin-dashboard.badge></div>
        <h2 class="break-words text-lg font-semibold text-admin-ink">{{ $item->name }}</h2>
        <p class="font-mono text-xs break-all text-admin-muted">SKU {{ $item->external_id }}</p>
    </header>
    <div class="grid gap-4 p-5">
        @php
            $cost = $unitCosts[$item->id] ?? ['idr' => null, 'usd_micros' => null];
            $usdCost = $item->unit_price_micros === null ? '—' : ($cost['usd_micros'] === null ? 'Kurs USD belum diatur' : '$'.\App\Services\UsdtBalance\UsdtMicroAmount::formatUnits($cost['usd_micros']));
            $idrCost = $item->unit_price_micros === null ? '—' : ($cost['idr'] === null ? 'Kurs belum diatur' : 'Rp'.number_format($cost['idr'], 0, ',', '.'));
        @endphp
        <dl class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3 sm:col-span-2">
                <dt class="text-xs font-medium text-admin-muted">Harga beli · {{ $supplier['currency'] }}</dt>
                <dd class="mt-1 text-lg font-semibold text-admin-ink">{{ $item->unit_price_micros === null ? 'Tidak diketahui' : \App\Services\Suppliers\SupplierMoney::decimal($item->unit_price_micros) }}</dd>
                <dd class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3">
                    <span class="block"><span class="block text-xs font-medium text-admin-muted">Setara USD</span><span class="mt-0.5 block break-words text-sm font-semibold text-admin-ink">{{ $usdCost }}</span></span>
                    <span class="block"><span class="block text-xs font-medium text-admin-muted">Setara rupiah</span><span class="mt-0.5 block break-words text-sm font-semibold text-admin-ink">{{ $idrCost }}</span></span>
                </dd>
            </div>
            <div><dt class="text-xs font-medium text-admin-muted">Stok supplier</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">{{ $item->stock ?? 'Tidak diketahui' }}</dd></div>
            <div><dt class="text-xs font-medium text-admin-muted">Ditahan</dt><dd class="mt-0.5 text-sm font-semibold text-admin-ink">{{ $item->reserved_quantity }}</dd></div>
        </dl>
        @if($item->requires_input)<x-admin-dashboard.notice tone="warning">Input/aktivasi manual: tidak bisa diaktifkan untuk pengiriman instan.</x-admin-dashboard.notice>@endif
        @if($item->description)<x-admin-dashboard.card as="details" padding="none" shadow="none"><summary class="min-h-11 cursor-pointer px-3 py-2 text-sm font-medium text-admin-ink">Deskripsi supplier privat</summary><p class="mx-3 mb-3 whitespace-pre-wrap break-words rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-sm leading-6 text-admin-muted">{{ $item->description }}</p></x-admin-dashboard.card>@endif
        <div><h3 class="text-xs font-medium text-admin-muted">Produk toko terhubung</h3><div class="mt-3 flex flex-wrap gap-2">@forelse($item->sources as $source)<x-admin-dashboard.button :href="route('admin.products.workspace', ['product' => $source->product_id, 'section' => 'supplier'])" variant="neutral" size="sm">#{{ $source->product_id }} {{ $source->product?->name ?? 'Produk dihapus' }}</x-admin-dashboard.button>@empty<p class="text-sm text-admin-muted">Belum dipetakan ke produk toko.</p>@endforelse</div></div>
    </div>
    <div class="grid gap-3 border-t border-admin-line p-5">
        <details>
            <summary class="min-h-11 cursor-pointer border-t border-admin-line pt-3 text-sm font-medium text-admin-ink">Impor sebagai draft</summary>
            <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3"><p class="mb-4 text-xs leading-6 text-admin-muted">Draft selalu nonaktif. Pengulangan impor tidak membuat duplikat.</p>
                <form method="POST" action="{{ route('admin.suppliers.import', $item) }}" class="grid gap-4">
                    @csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}">
                    <label class="grid gap-2 text-sm font-medium text-admin-ink">Nama produk toko<x-admin-dashboard.input name="name" required maxlength="255" /></label>
                    <label class="grid gap-2 text-sm font-medium text-admin-ink">Deskripsi draft toko<x-admin-dashboard.textarea name="description" maxlength="10000" rows="3" /></label>
                    <label class="grid gap-2 text-sm font-medium text-admin-ink">Harga jual ke pembeli (Rp)<x-admin-dashboard.input name="price_amount" type="number" min="1" max="1000000000" required /></label>
                    <x-admin-dashboard.button type="submit" variant="primary" size="sm">Impor draft nonaktif</x-admin-dashboard.button>
                </form>
            </div>
        </details>
        <details>
            <summary class="min-h-11 cursor-pointer border-t border-admin-line pt-3 text-sm font-medium text-admin-ink">Hubungkan ke produk toko yang sudah ada</summary>
            @php($availableProducts = $products->whereNotIn('id', $item->sources->pluck('product_id')))
            <form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="mt-3 grid gap-4 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                @csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}"><input type="hidden" name="version" value="0">
                <label class="grid gap-2 text-sm font-medium text-admin-ink">Produk toko
                    <x-admin-dashboard.select name="product_id" required :disabled="$availableProducts->isEmpty()">
                        <option value="" selected disabled>Pilih produk yang ingin dihubungkan</option>
                        @foreach($availableProducts as $storeProduct)
                            <option value="{{ $storeProduct->id }}">{{ $storeProduct->name }} · Rp{{ number_format($storeProduct->price_amount, 0, ',', '.') }} · {{ $storeProduct->is_active ? 'Aktif' : 'Draft / nonaktif' }} · #{{ $storeProduct->id }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </label>
                @if($availableProducts->isEmpty())
                    <p class="text-sm text-admin-muted">Tidak ada produk yang bisa dihubungkan. Semua produk toko sudah terhubung ke SKU ini, atau belum ada produk toko. Impor draft di atas untuk membuat produk baru; gunakan tombol produk terhubung untuk mengedit pemetaan yang sudah ada.</p>
                @else
                    <p class="text-xs leading-6 text-admin-muted">Satu produk toko dapat dihubungkan ke beberapa supplier. Produk yang sudah terhubung ke SKU ini tidak ditampilkan lagi.</p>
                @endif
                <label class="grid gap-2 text-sm font-medium text-admin-ink">Urutan supplier (angka lebih kecil didahulukan)<x-admin-dashboard.input name="priority" type="number" min="0" max="10000" value="100" required /></label>
                <input type="hidden" name="is_active" value="0"><label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink"><input type="checkbox" name="is_active" value="1" class="h-5 w-5 rounded border-admin-line accent-admin-accent" @disabled($item->requires_input || !$item->orderable)> Aktif</label>
                <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink"><input type="checkbox" name="equivalent_confirmed" value="1" required class="h-5 w-5 rounded border-admin-line accent-admin-accent"> Saya memastikan paket, durasi, dan garansinya sesuai produk toko.</label>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" :disabled="$availableProducts->isEmpty()">Simpan hubungan produk</x-admin-dashboard.button>
            </form>
        </details>
    </div>
</x-admin-dashboard.card>
@empty
    <x-admin-dashboard.card padding="compact" shadow="sm" class="text-center lg:col-span-2"><h2 class="text-lg font-semibold text-admin-ink">Katalog masih kosong</h2><p class="mt-1 text-sm leading-6 text-admin-muted">Kembali ke Supplier dashboard lalu sinkronkan katalog dan saldo untuk membaca produk supplier.</p><x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'suppliers'])" variant="neutral" size="sm" class="mt-4">Kembali ke dashboard <span aria-hidden="true">→</span></x-admin-dashboard.button></x-admin-dashboard.card>
@endforelse
</div>
{{ $items->links() }}
@endsection
