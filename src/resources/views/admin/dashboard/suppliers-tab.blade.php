<section class="mt-5 grid min-w-0 gap-5" aria-labelledby="supplier-title">
    <div class="rounded-2xl border-2 bg-white p-4 text-neo-ink">
        <h1 id="supplier-title" class="text-xl font-black">Supplier Reseller</h1>
        <p class="mt-2">Katalog dan pembelian supplier bersifat privat. Stok eksternal bukan inventori lokal. Kredensial tidak ditampilkan kembali.</p>
        <p class="mt-2">Perubahan API key/provider dan tindakan pemulihan memerlukan konfirmasi password dalam 15 menit. Komitmen aktif harus diselesaikan sebelum mengganti akun atau mata uang.</p>
        <div class="mt-3 flex flex-wrap gap-3"><a class="underline" href="{{ route('password.confirm') }}">Konfirmasi password</a><a class="underline" href="{{ route('admin.suppliers.purchases') }}">Monitor pembelian / pemulihan</a></div>
    </div>
    <details class="rounded-2xl border-2 bg-white p-4 text-neo-ink"><summary class="cursor-pointer font-bold">Tambah supplier</summary><div class="mt-4">@include('admin.suppliers.connection-form', ['connection' => []])</div></details>
    @foreach($suppliersTab['connections'] as $connection)
        <article class="rounded-2xl border-2 bg-white p-4 text-neo-ink">
            <h2 class="text-lg font-bold">{{ $connection['name'] }} · {{ $connection['provider'] }}</h2>
            <p>Saldo {{ $connection['balance'] }} {{ $connection['currency'] }} · Ditahan {{ $connection['held_balance'] }} · Tersedia {{ $connection['available_balance'] }}</p>
            @if($connection['low_balance'])<p role="status" class="mt-2 font-bold text-red-700">Saldo supplier rendah / tidak mencukupi. Isi saldo sebelum mencoba kembali fulfillment.</p>@endif
            <p class="mt-2 break-words text-sm">Sinkron terakhir: {{ $connection['synced_at'] ?? 'Belum sinkron' }} · Berlaku sampai {{ $connection['expires_at'] ?? 'Tidak segar' }} · Versi {{ $connection['version'] }}</p>
            <div class="my-3 flex flex-wrap items-center gap-3"><a class="underline" href="{{ route('admin.suppliers.catalog', $connection['id']) }}">Katalog privat / impor / pemetaan</a><form method="POST" action="{{ route('admin.suppliers.sync', $connection['id']) }}">@csrf<button class="rounded border px-3 py-2 font-bold">Sinkron katalog dan saldo (GET supplier)</button></form></div>
            <details><summary class="cursor-pointer font-bold">Edit koneksi</summary><div class="mt-4">@include('admin.suppliers.connection-form')</div></details>
        </article>
    @endforeach
    {{ $suppliersTab['connections']->links() }}
    <div class="rounded-2xl border-2 bg-white p-4 text-neo-ink">
        <h2 class="font-bold">Sumber supplier &amp; kebijakan harga produk</h2>
        <p>Nama, deskripsi, dan status publik diedit melalui tab Produk. Impor selalu menghasilkan draft nonaktif tanpa menyalin deskripsi supplier.</p>
        <ul class="mt-3 grid gap-2">@foreach($suppliersTab['products'] as $supplierProduct)<li><a class="underline" href="{{ route('admin.products.suppliers', $supplierProduct) }}">#{{ $supplierProduct->id }} {{ $supplierProduct->name }} · IDR {{ number_format($supplierProduct->price_amount, 0, ',', '.') }}</a></li>@endforeach</ul>
        <div class="mt-3">{{ $suppliersTab['products']->links() }}</div>
    </div>
</section>
