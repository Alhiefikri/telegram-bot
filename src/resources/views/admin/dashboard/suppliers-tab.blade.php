<section id="admin-suppliers-workspace" data-supplier-workspace class="mt-5 grid min-w-0 gap-6" aria-labelledby="supplier-title">
    <x-admin-dashboard.card as="header" padding="none" shadow="sm" class="overflow-hidden">
        <div class="border-b border-admin-line bg-admin-accent-soft p-5 sm:p-6">
            <p class="text-xs font-medium text-admin-muted">Operasional toko · Akses privat</p>
            <h1 id="supplier-title" class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Supplier Reseller</h1>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-admin-muted">Kelola koneksi, pantau saldo, dan hubungkan katalog supplier ke produk toko. Stok eksternal bukan inventori lokal.</p>
        </div>
        <div class="grid gap-4 p-5 sm:p-6">
            <nav class="flex flex-wrap gap-3" aria-label="Operasional supplier">
                <x-admin-dashboard.button :href="route('admin.suppliers.purchases')" variant="primary" size="sm">Monitor pembelian / pemulihan <span aria-hidden="true">↗</span></x-admin-dashboard.button>
                <x-admin-dashboard.button :href="route('admin.suppliers.warranty')" variant="neutral" size="sm">Klaim Gmail / refund / penggantian <span aria-hidden="true">↗</span></x-admin-dashboard.button>
                <x-admin-dashboard.button :href="route('password.confirm')" variant="neutral" size="sm">Konfirmasi password</x-admin-dashboard.button>
            </nav>
            <p class="text-sm leading-6 text-admin-muted">API key disimpan privat dan tidak ditampilkan kembali. Konfirmasi password muncul otomatis saat diperlukan, berlaku 15 menit, dan tidak menghapus isian form. Selesaikan pembelian berjalan sebelum mengganti akun atau mata uang.</p>
        </div>
    </x-admin-dashboard.card>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-xs font-medium text-admin-muted">Koneksi supplier</p><h2 class="mt-1 text-lg font-semibold text-admin-ink">Sumber stok eksternal</h2></div>
        <x-admin-dashboard.badge>{{ $suppliersTab['connections']->total() }} koneksi</x-admin-dashboard.badge>
    </div>
    <x-admin-dashboard.card as="details" padding="none" shadow="sm">
        <summary class="min-h-11 cursor-pointer p-5 text-sm font-medium text-admin-ink">Tambah supplier</summary>
        <div class="mx-5 mb-5 rounded-lg border border-admin-line bg-admin-surface-muted p-3">@include('admin.suppliers.connection-form', ['connection' => []])</div>
    </x-admin-dashboard.card>

    <div class="grid min-w-0 gap-5 lg:grid-cols-2">
        @forelse($suppliersTab['connections'] as $connection)
            <x-admin-dashboard.card as="article" padding="none" shadow="sm" class="flex min-w-0 flex-col overflow-hidden">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-admin-line p-5">
                    <div class="min-w-0"><p class="text-xs font-medium text-admin-muted">{{ \App\Enums\SupplierProvider::from($connection['provider'])->label() }}</p><h3 class="mt-1 break-words text-lg font-semibold text-admin-ink">{{ $connection['name'] }}</h3></div>
                    <x-admin-dashboard.badge :tone="$connection['is_active'] ? 'emerald' : 'slate'">{{ $connection['is_active'] ? 'Aktif' : 'Nonaktif' }}</x-admin-dashboard.badge>
                </header>
                <div class="grid flex-1 gap-4 p-5">
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3 sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Saldo tersedia · {{ $connection['currency'] }}</dt><dd class="mt-1 break-words text-lg font-semibold text-admin-ink">{{ $connection['available_balance'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Saldo total · {{ $connection['currency'] }}</dt><dd class="mt-0.5 break-words text-sm font-semibold text-admin-ink">{{ $connection['balance'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Ditahan · {{ $connection['currency'] }}</dt><dd class="mt-0.5 break-words text-sm font-semibold text-admin-ink">{{ $connection['held_balance'] }}</dd></div>
                    </dl>
                    @if($connection['low_balance'])<x-admin-dashboard.notice tone="warning" role="status">Saldo supplier rendah / tidak mencukupi. Isi saldo sebelum mencoba kembali fulfillment.</x-admin-dashboard.notice>@endif
                    @if($connection['has_unresolved_hold'])<x-admin-dashboard.notice tone="warning" role="status">Dana ditahan oleh purchase yang belum terbukti. Saldo tersedia sudah dikurangi hold; jangan menganggap debit supplier sudah pasti terjadi atau melepasnya tanpa bukti.</x-admin-dashboard.notice>@endif
                    @if($connection['balance_uncertain'])<x-admin-dashboard.notice tone="warning" role="status">Observasi saldo belum segar atau belum tersedia. Kapasitas pembelian aman dapat nol sampai observasi wallet baru diterima.</x-admin-dashboard.notice>@endif
                    @if($connection['sync_error'])<x-admin-dashboard.notice tone="danger" role="status">{{ $connection['sync_error'] }}</x-admin-dashboard.notice>@endif
                    @if($connection['refunds_disabled'])<x-admin-dashboard.notice tone="warning" role="status">Refund supplier dinonaktifkan. Sumber Gmail ini tidak menerima pembelian baru sampai dukungan refund aktif kembali.</x-admin-dashboard.notice>@endif
                    @if($connection['purchase_paused_until'])<x-admin-dashboard.notice tone="warning" role="status">Batas harian supplier tercapai. Pembelian baru ditunda sampai {{ $connection['purchase_paused_until'] }}.</x-admin-dashboard.notice>@endif
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div><dt class="text-xs font-medium text-admin-muted">Sinkron terakhir</dt><dd class="mt-0.5 break-words text-sm font-medium text-admin-ink">{{ $connection['synced_at'] ?? 'Belum sinkron' }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Saldo terakhir diamati</dt><dd class="mt-0.5 break-words text-sm font-medium text-admin-ink">{{ $connection['balance_observed_at'] ?? 'Belum pernah diamati' }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Berlaku sampai</dt><dd class="mt-0.5 break-words text-sm font-medium text-admin-ink">{{ $connection['expires_at'] ?? 'Tidak segar' }} · Versi {{ $connection['version'] }}</dd></div>
                    </dl>
                    @if($connection['webhook_url'])
                        <x-admin-dashboard.card as="details" padding="none" shadow="none">
                            <summary class="min-h-11 cursor-pointer px-3 py-2 text-sm font-medium text-admin-ink">Pengaturan webhook BuyMail</summary>
                            <div class="mx-3 mb-3 grid gap-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-sm">
                                <p class="break-all text-admin-muted">URL webhook untuk portal BuyMail: <span class="font-mono text-xs break-all text-admin-ink">{{ $connection['webhook_url'] }}</span></p>
                                <p class="text-admin-muted">Secret {{ $connection['webhook_configured'] ? 'tersimpan' : 'belum diatur' }}. Aktifkan API dan IP allowlist dari portal reseller.</p>
                            </div>
                        </x-admin-dashboard.card>
                    @endif
                    <div class="mt-auto flex flex-wrap gap-3">
                        <x-admin-dashboard.button :href="route('admin.suppliers.catalog', $connection['id'])" variant="primary" size="sm">Katalog privat / impor / pemetaan <span aria-hidden="true">→</span></x-admin-dashboard.button>
                        <form method="POST" action="{{ route('admin.suppliers.sync', $connection['id']) }}" x-data="{ syncing: false }" x-on:submit="if (syncing) { $event.preventDefault() } else { syncing = true }">@csrf<x-admin-dashboard.button type="submit" variant="neutral" size="sm" x-bind:disabled="syncing" x-text="syncing ? 'Sedang memperbarui katalog...' : 'Sinkron katalog dan saldo'">Sinkron katalog dan saldo</x-admin-dashboard.button></form>
                        @if($connection['provider'] === 'buymail')<x-admin-dashboard.button :href="route('admin.suppliers.buymail', $connection['id'])" variant="neutral" size="sm">Deposit BuyMail &amp; histori privat <span aria-hidden="true">↗</span></x-admin-dashboard.button>@endif
                    </div>
                </div>
                <details class="border-t border-admin-line"><summary class="min-h-11 cursor-pointer px-5 py-3 text-sm font-medium text-admin-ink">Edit koneksi</summary><div class="px-5 pb-5"><div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">@include('admin.suppliers.connection-form')</div></div></details>
            </x-admin-dashboard.card>
        @empty
            <x-admin-dashboard.card padding="compact" shadow="sm" class="text-center lg:col-span-2"><h3 class="text-lg font-semibold text-admin-ink">Belum ada koneksi supplier</h3><p class="mt-1 text-sm leading-6 text-admin-muted">Buka Tambah supplier untuk menghubungkan akun, lalu sinkronkan katalog dan saldo.</p></x-admin-dashboard.card>
        @endforelse
    </div>
    {{ $suppliersTab['connections']->links() }}

    <x-admin-dashboard.card as="section" id="admin-supplier-products" padding="none" shadow="sm" class="scroll-mt-6 overflow-hidden" aria-labelledby="supplier-products-title">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-admin-line p-5 sm:p-6">
            <div class="max-w-2xl"><p class="text-xs font-medium text-admin-muted">Katalog toko</p><h2 id="supplier-products-title" class="mt-1 text-lg font-semibold text-admin-ink">Sumber supplier &amp; kebijakan harga produk</h2><p class="mt-1 text-sm leading-6 text-admin-muted">Atur sumber stok dan harga jual per produk. Nama, deskripsi, dan status publik diedit melalui tab Produk. Impor selalu menghasilkan draft nonaktif tanpa menyalin deskripsi supplier.</p></div>
            <x-admin-dashboard.badge>{{ $suppliersTab['products']->total() }} produk</x-admin-dashboard.badge>
        </header>
        @php($productFilters = $suppliersTab['productFilters'] ?? ['search' => '', 'status' => 'all', 'source' => 'all', 'sourceOptions' => collect()])
        <form method="GET" action="{{ route('admin.dashboard') }}#admin-supplier-products" class="grid gap-3 border-b border-admin-line bg-admin-surface-muted p-5 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end sm:px-6" aria-label="Filter produk supplier">
            <input type="hidden" name="tab" value="suppliers">
            <label class="grid gap-1.5 text-sm font-medium text-admin-ink">
                <span>Cari produk</span>
                <x-admin-dashboard.input name="supplier_search" type="search" value="{{ $productFilters['search'] }}" placeholder="Nama atau ID produk" aria-label="Cari nama atau ID produk" />
            </label>
            <label class="grid gap-1.5 text-sm font-medium text-admin-ink">
                <span>Status produk</span>
                <x-admin-dashboard.select name="supplier_status" aria-label="Filter status produk">
                    <option value="all" @selected($productFilters['status'] === 'all')>Semua status</option>
                    <option value="active" @selected($productFilters['status'] === 'active')>Aktif</option>
                    <option value="inactive" @selected($productFilters['status'] === 'inactive')>Draft / nonaktif</option>
                </x-admin-dashboard.select>
            </label>
            <label class="grid gap-1.5 text-sm font-medium text-admin-ink">
                <span>Sumber supplier</span>
                <x-admin-dashboard.select name="supplier_source" aria-label="Filter sumber supplier">
                    <option value="all" @selected($productFilters['source'] === 'all')>Semua sumber</option>
                    <option value="mapped" @selected($productFilters['source'] === 'mapped')>Sudah dipetakan</option>
                    <option value="unmapped" @selected($productFilters['source'] === 'unmapped')>Belum dipetakan</option>
                    @foreach($productFilters['sourceOptions'] as $sourceOption)
                        <option value="{{ $sourceOption->id }}" @selected((string) $productFilters['source'] === (string) $sourceOption->id)>{{ $sourceOption->name }}</option>
                    @endforeach
                </x-admin-dashboard.select>
            </label>
            <x-admin-dashboard.button type="submit" variant="primary" size="sm">Terapkan filter</x-admin-dashboard.button>
        </form>
        @if($suppliersTab['products']->total() === 0 && ($productFilters['search'] !== '' || $productFilters['status'] !== 'all' || $productFilters['source'] !== 'all'))
            <div class="border-b border-admin-line p-6 text-center" role="status">
                <h3 class="font-semibold text-admin-ink">Tidak ada produk yang cocok</h3>
                <p class="mt-1 text-sm leading-6 text-admin-muted">Coba ubah kata kunci atau sumber supplier, lalu terapkan filter lagi.</p>
                <a href="{{ route('admin.dashboard', ['tab' => 'suppliers']) }}#admin-supplier-products" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line px-3 text-sm font-medium text-admin-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admin-focus">Hapus filter</a>
            </div>
        @else
            <div class="grid gap-3 p-5 sm:grid-cols-2 sm:px-6">
                @forelse($suppliersTab['products'] as $supplierProduct)
                    @php($mappedSources = $supplierProduct->supplierSources)
                    @php($policy = $supplierProduct->supplierPricePolicy)
                    <article class="grid min-w-0 gap-4 rounded-lg border border-admin-line bg-admin-surface p-4">
                        <header class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs text-admin-muted">#{{ $supplierProduct->id }}</span>
                                    <x-admin-dashboard.badge :tone="$supplierProduct->is_active ? 'emerald' : 'slate'">{{ $supplierProduct->is_active ? 'Aktif' : 'Draft / nonaktif' }}</x-admin-dashboard.badge>
                                </div>
                                <h3 class="mt-2 break-words text-sm font-semibold text-admin-ink">{{ $supplierProduct->name }}</h3>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-medium text-admin-muted">Harga jual</p>
                                <p class="mt-0.5 whitespace-nowrap text-sm font-semibold text-admin-ink">Rp{{ number_format($supplierProduct->price_amount, 0, ',', '.') }}</p>
                            </div>
                        </header>
                        <dl class="grid gap-2 text-sm">
                            <div>
                                <dt class="text-xs font-medium text-admin-muted">Mapping supplier</dt>
                                <dd class="mt-1 text-admin-ink">
                                    @forelse($mappedSources as $mappedSource)
                                        <span class="block break-words">{{ $mappedSource->catalogItem?->supplier?->name ?? 'Supplier dihapus' }} · {{ $mappedSource->catalogItem?->name ?? 'Item katalog dihapus' }} <span class="text-admin-muted">(urutan {{ $mappedSource->priority }}, {{ $mappedSource->is_active ? 'aktif' : 'nonaktif' }})</span></span>
                                    @empty
                                        <span class="text-admin-muted">Belum dipetakan ke supplier.</span>
                                    @endforelse
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-admin-muted">Kebijakan harga</dt>
                                <dd class="mt-1 break-words text-admin-ink">
                                    {{ match($policy?->mode) { 'percent' => 'Otomatis · persentase', 'fixed' => 'Otomatis · keuntungan tetap', 'hybrid' => 'Otomatis · persentase + minimum', default => 'Manual' } }}
                                    @if($policy && $policy->mode !== 'manual')
                                        <span class="text-admin-muted"> · pembulatan Rp{{ number_format($policy->rounding_idr, 0, ',', '.') }}</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <x-admin-dashboard.button :href="route('admin.products.suppliers', $supplierProduct)" variant="neutral" size="sm" aria-label="Atur supplier dan harga {{ $supplierProduct->name }}">Atur supplier &amp; harga <span aria-hidden="true">→</span></x-admin-dashboard.button>
                    </article>
                @empty
                    <div class="p-3 text-center sm:col-span-2" role="status">
                        <h3 class="font-semibold text-admin-ink">Belum ada produk toko</h3>
                        <p class="mt-1 text-sm leading-6 text-admin-muted">Impor draft dari katalog privat supplier atau tambahkan produk melalui tab Produk.</p>
                    </div>
                @endforelse
            </div>
        @endif
        @if($suppliersTab['products']->hasPages())<div class="border-t border-admin-line p-5">{{ $suppliersTab['products']->links() }}</div>@endif
    </x-admin-dashboard.card>
</section>
