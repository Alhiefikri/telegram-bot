@extends('admin.suppliers.layout')
@section('title', 'Katalog privat · '.$supplier['name'])
@section('content')
<p>Provider {{ $supplier['provider'] }} · {{ $supplier['currency'] }} · IDR {{ $supplier['exchange_rate_idr'] }} / unit · versi {{ $supplier['version'] }}. Deskripsi di bawah hanya referensi privat, tidak pernah dipublikasikan otomatis.</p>
@foreach($items as $item)
<article class="grid gap-3 rounded-2xl border-2 bg-white p-4">
    <h2 class="font-bold">#{{ $item->id }} {{ $item->name }}</h2>
    <p class="break-words">SKU {{ $item->external_id }} · Harga {{ $item->unit_price_micros === null ? 'Tidak diketahui' : \App\Services\Suppliers\SupplierMoney::decimal($item->unit_price_micros) }} {{ $supplier['currency'] }} · Stok {{ $item->stock ?? 'Tidak diketahui' }} · Ditahan {{ $item->reserved_quantity }}</p>
    <p>{{ $item->requires_input ? 'Input/aktivasi manual: tidak bisa diaktifkan untuk pengiriman instan.' : ($item->orderable ? 'Pengiriman instan didukung.' : 'Tidak dapat dipesan.') }}</p>
    @if($item->description)<details><summary>Deskripsi supplier privat</summary><p class="whitespace-pre-wrap break-words">{{ $item->description }}</p></details>@endif
    <p>Pemetaan: @forelse($item->sources as $source)<a class="underline" href="{{ route('admin.products.suppliers', $source->product_id) }}">#{{ $source->product_id }} {{ $source->product?->name ?? 'Produk dihapus' }}</a> @empty belum dipetakan @endforelse</p>
    <details><summary class="cursor-pointer font-bold">Impor sebagai draft (pengulangan tidak membuat duplikat)</summary>
        <form method="POST" action="{{ route('admin.suppliers.import', $item) }}" class="mt-3 grid gap-3">@csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}"><label>Nama produk toko <input class="w-full rounded border p-2" name="name" required maxlength="255"></label><label>Deskripsi draft toko <textarea class="w-full rounded border p-2" name="description" maxlength="10000"></textarea></label><label>Harga retail IDR <input class="w-full rounded border p-2" name="price_amount" type="number" min="1" max="1000000000" required></label><button class="rounded border-2 px-3 py-2 font-bold">Impor draft nonaktif</button></form>
    </details>
    <details><summary class="cursor-pointer font-bold">Petakan ke produk ekuivalen yang sudah ada</summary>
        <form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="mt-3 grid gap-3 sm:grid-cols-2">@csrf<input type="hidden" name="supplier_version" value="{{ $supplier['version'] }}"><input type="hidden" name="version" value="0"><label>ID produk toko <input class="w-full rounded border p-2" name="product_id" type="number" min="1" required></label><label>Prioritas (lebih kecil didahulukan) <input class="w-full rounded border p-2" name="priority" type="number" min="0" max="10000" value="100" required></label><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @disabled($item->requires_input || !$item->orderable)> Aktif</label><label><input type="checkbox" name="equivalent_confirmed" value="1" required> Saya memverifikasi SKU benar-benar ekuivalen dengan produk toko.</label><button class="rounded border-2 px-3 py-2 font-bold sm:col-span-2">Simpan pemetaan</button></form>
    </details>
</article>
@endforeach
{{ $items->links() }}
@endsection
