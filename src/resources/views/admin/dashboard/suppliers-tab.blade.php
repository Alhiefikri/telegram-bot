<section class="supplier-workspace mt-5 grid min-w-0 gap-6" aria-labelledby="supplier-title">
    <header class="supplier-panel overflow-hidden">
        <div class="border-b-2 border-neo-ink bg-neo-purple-soft p-5 sm:p-6">
            <p class="supplier-eyebrow">Operasional toko · Akses privat</p>
            <h1 id="supplier-title" class="mt-2 text-2xl font-black sm:text-3xl">Supplier Reseller</h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed">Kelola koneksi, pantau saldo, dan hubungkan katalog supplier ke produk toko. Stok eksternal bukan inventori lokal.</p>
        </div>
        <div class="grid gap-4 p-5 sm:p-6">
            <nav class="flex flex-wrap gap-3" aria-label="Operasional supplier">
                <a class="supplier-button supplier-button-primary" href="{{ route('admin.suppliers.purchases') }}">Monitor pembelian / pemulihan <span aria-hidden="true">↗</span></a>
                <a class="supplier-button" href="{{ route('admin.suppliers.warranty') }}">Klaim Gmail / refund / penggantian <span aria-hidden="true">↗</span></a>
                <a class="supplier-button" href="{{ route('password.confirm') }}">Konfirmasi password</a>
            </nav>
            <p class="supplier-muted text-sm leading-relaxed">API key disimpan privat dan tidak ditampilkan kembali. Konfirmasi password muncul otomatis saat diperlukan, berlaku 15 menit, dan tidak menghapus isian form. Selesaikan pembelian berjalan sebelum mengganti akun atau mata uang.</p>
        </div>
    </header>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="supplier-eyebrow">Koneksi supplier</p><h2 class="mt-1 text-xl font-black">Sumber stok eksternal</h2></div>
        <span class="supplier-badge">{{ $suppliersTab['connections']->total() }} koneksi</span>
    </div>
    <details class="supplier-panel supplier-disclosure">
        <summary class="flex items-center justify-between gap-3 p-5 font-bold"><span>Tambah supplier</span><span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary>
        <div class="border-t-2 border-neo-ink p-5 sm:p-6">@include('admin.suppliers.connection-form', ['connection' => []])</div>
    </details>

    <div class="grid min-w-0 gap-5 lg:grid-cols-2">
        @forelse($suppliersTab['connections'] as $connection)
            <article class="supplier-panel flex min-w-0 flex-col overflow-hidden">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b-2 border-neo-ink p-5">
                    <div class="min-w-0"><p class="supplier-eyebrow">{{ \App\Enums\SupplierProvider::from($connection['provider'])->label() }}</p><h3 class="mt-1 break-words text-xl font-black">{{ $connection['name'] }}</h3></div>
                    <span class="supplier-badge {{ $connection['is_active'] ? 'bg-neo-success-soft text-neo-success-text' : '' }}">{{ $connection['is_active'] ? 'Aktif' : 'Nonaktif' }}</span>
                </header>
                <div class="grid flex-1 gap-4 p-5">
                    <dl class="grid grid-cols-2 gap-3">
                        <div class="supplier-metric col-span-2 bg-neo-info-soft"><dt class="text-sm font-bold">Saldo tersedia <span class="font-normal">· {{ $connection['currency'] }}</span></dt><dd class="mt-1 break-words text-3xl font-black">{{ $connection['available_balance'] }}</dd></div>
                        <div class="supplier-metric"><dt class="supplier-muted text-xs font-bold">Saldo total · {{ $connection['currency'] }}</dt><dd class="mt-1 break-words text-lg font-bold">{{ $connection['balance'] }}</dd></div>
                        <div class="supplier-metric"><dt class="supplier-muted text-xs font-bold">Ditahan · {{ $connection['currency'] }}</dt><dd class="mt-1 break-words text-lg font-bold">{{ $connection['held_balance'] }}</dd></div>
                    </dl>
                    @if($connection['low_balance'])<p role="status" class="supplier-alert">Saldo supplier rendah / tidak mencukupi. Isi saldo sebelum mencoba kembali fulfillment.</p>@endif
                    @if($connection['sync_error'])<p role="status" class="supplier-alert">{{ $connection['sync_error'] }}</p>@endif
                    @if($connection['refunds_disabled'])<p role="status" class="supplier-alert">Refund supplier dinonaktifkan. Sumber Gmail ini tidak menerima pembelian baru sampai dukungan refund aktif kembali.</p>@endif
                    @if($connection['purchase_paused_until'])<p role="status" class="supplier-alert">Batas harian supplier tercapai. Pembelian baru ditunda sampai {{ $connection['purchase_paused_until'] }}.</p>@endif
                    <dl class="supplier-muted grid gap-2 text-xs leading-relaxed">
                        <div><dt class="font-bold">Sinkron terakhir</dt><dd class="break-words">{{ $connection['synced_at'] ?? 'Belum sinkron' }}</dd></div>
                        <div><dt class="font-bold">Berlaku sampai</dt><dd class="break-words">{{ $connection['expires_at'] ?? 'Tidak segar' }} · Versi {{ $connection['version'] }}</dd></div>
                    </dl>
                    @if($connection['webhook_url'])
                        <details class="supplier-disclosure rounded-xl border border-neo-ink/15 p-3 text-sm"><summary class="font-bold">Pengaturan webhook BuyMail <span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary><div class="mt-3 grid gap-2"><p class="break-all">URL webhook untuk portal BuyMail: {{ $connection['webhook_url'] }}</p><p class="supplier-muted">Secret {{ $connection['webhook_configured'] ? 'tersimpan' : 'belum diatur' }}. Aktifkan API dan IP allowlist dari portal reseller.</p></div></details>
                    @endif
                    <div class="mt-auto flex flex-wrap gap-3">
                        <a class="supplier-button supplier-button-primary" href="{{ route('admin.suppliers.catalog', $connection['id']) }}">Katalog privat / impor / pemetaan <span aria-hidden="true">→</span></a>
                        <form method="POST" action="{{ route('admin.suppliers.sync', $connection['id']) }}" x-data="{ syncing: false }" x-on:submit="if (syncing) { $event.preventDefault() } else { syncing = true }">@csrf<button class="supplier-button" x-bind:disabled="syncing" x-text="syncing ? 'Sedang memperbarui katalog...' : 'Sinkron katalog dan saldo'">Sinkron katalog dan saldo</button></form>
                        @if($connection['provider'] === 'buymail')<a class="supplier-button" href="{{ route('admin.suppliers.buymail', $connection['id']) }}">Deposit BuyMail &amp; histori privat <span aria-hidden="true">↗</span></a>@endif
                    </div>
                </div>
                <details class="supplier-disclosure border-t-2 border-neo-ink"><summary class="flex items-center justify-between gap-3 p-5 text-sm font-bold">Edit koneksi <span class="supplier-disclosure-mark" aria-hidden="true">+</span></summary><div class="px-5 pb-5">@include('admin.suppliers.connection-form')</div></details>
            </article>
        @empty
            <div class="supplier-panel p-8 text-center lg:col-span-2"><h3 class="text-lg font-black">Belum ada koneksi supplier</h3><p class="supplier-muted mt-2 text-sm">Buka Tambah supplier untuk menghubungkan akun, lalu sinkronkan katalog dan saldo.</p></div>
        @endforelse
    </div>
    {{ $suppliersTab['connections']->links() }}

    <section class="supplier-panel overflow-hidden" aria-labelledby="supplier-products-title">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b-2 border-neo-ink p-5 sm:p-6">
            <div class="max-w-2xl"><p class="supplier-eyebrow">Katalog toko</p><h2 id="supplier-products-title" class="mt-1 text-xl font-black">Sumber supplier &amp; kebijakan harga produk</h2><p class="supplier-muted mt-2 text-sm leading-relaxed">Atur sumber stok dan harga jual per produk. Nama, deskripsi, dan status publik diedit melalui tab Produk. Impor selalu menghasilkan draft nonaktif tanpa menyalin deskripsi supplier.</p></div>
            <span class="supplier-badge">{{ $suppliersTab['products']->total() }} produk</span>
        </header>
        <ul class="divide-y divide-neo-ink/15">
            @forelse($suppliersTab['products'] as $supplierProduct)
                <li class="grid min-w-0 items-center gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:px-6">
                    <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="supplier-muted text-xs font-bold">#{{ $supplierProduct->id }}</span><span class="supplier-badge {{ $supplierProduct->is_active ? 'bg-neo-success-soft text-neo-success-text' : '' }}">{{ $supplierProduct->is_active ? 'Aktif' : 'Draft / nonaktif' }}</span></div><h3 class="mt-2 break-words font-bold">{{ $supplierProduct->name }}</h3></div>
                    <div><p class="supplier-muted text-xs">Harga jual</p><p class="mt-1 whitespace-nowrap text-lg font-black">Rp{{ number_format($supplierProduct->price_amount, 0, ',', '.') }}</p></div>
                    <a class="supplier-button" aria-label="Atur supplier dan harga {{ $supplierProduct->name }}" href="{{ route('admin.products.suppliers', $supplierProduct) }}">Atur supplier &amp; harga <span aria-hidden="true">→</span></a>
                </li>
            @empty
                <li class="p-8 text-center"><h3 class="font-bold">Belum ada produk toko</h3><p class="supplier-muted mt-2 text-sm">Impor draft dari katalog privat supplier atau tambahkan produk melalui tab Produk.</p></li>
            @endforelse
        </ul>
        @if($suppliersTab['products']->hasPages())<div class="border-t border-neo-ink/15 p-5">{{ $suppliersTab['products']->links() }}</div>@endif
    </section>
</section>
