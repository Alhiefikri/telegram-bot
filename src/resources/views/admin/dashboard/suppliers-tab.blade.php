@php
    $providerLabels = ['prodseller' => 'ProdSeller', 'csxstore' => 'CSxStore', 'arrs' => 'ARRS', 'warzone' => 'Warzone', 'aivault' => 'AI Vault Hub'];
    $failedConnectionId = (int) old('context_id', 0);
    $passwordConfirmedRecently = $passwordConfirmedRecently ?? true;
@endphp
<section id="admin-section-suppliers" class="mt-5 grid min-w-0 gap-5" aria-labelledby="supplier-title">
    <x-admin-dashboard.card padding="compact" shadow="sm" class="min-w-0">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 id="supplier-title" class="font-display text-xl font-black uppercase text-neo-ink">Supplier Reseller</h1>
                <p class="mt-2 text-sm font-semibold leading-6 text-brand-muted">
                    Pilih SKU dari katalog supplier, tentukan harga jualnya, lalu tayangkan produk. Nama supplier, modal, dan kredensial hanya terlihat oleh Owner/Admin.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <x-admin-dashboard.button href="{{ route('admin.suppliers.purchases') }}" variant="neutral" size="sm">Monitor pembelian</x-admin-dashboard.button>
                <x-admin-dashboard.button href="{{ route('password.confirm') }}" variant="warning" size="sm">Konfirmasi password</x-admin-dashboard.button>
            </div>
        </div>

        @if (! $passwordConfirmedRecently)
            <x-admin-dashboard.notice tone="warning" role="alert" class="mt-3 text-xs">
                Sesi konfirmasi password sudah berakhir.
                <a class="underline" href="{{ route('password.confirm') }}">Konfirmasi password dulu</a>
                sebelum menyimpan kredensial atau tindakan pemulihan.
            </x-admin-dashboard.notice>
        @endif
    </x-admin-dashboard.card>

    @if ($errors->any())
        <x-admin-dashboard.notice tone="danger" role="alert" class="animate-slide-up rounded-xl">
            <p class="font-black uppercase tracking-wide">Periksa kembali isian berikut</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm font-bold">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-admin-dashboard.notice>
    @endif

    <x-admin-dashboard.card padding="compact" shadow="sm" class="min-w-0">
        <details @if ($errors->any() && $failedConnectionId === 0) open @endif>
            <summary class="cursor-pointer font-display text-base font-black uppercase text-neo-ink">+ Tambah supplier</summary>
            <p class="mt-2 text-xs font-bold text-brand-muted">Tambahkan koneksi, isi API key dari supplier, lalu sinkronkan katalognya. API key tidak akan ditampilkan lagi setelah disimpan.</p>
            <div class="mt-4">
                @include('admin.suppliers.connection-form', ['connection' => [], 'repopulate' => true])
            </div>
        </details>
    </x-admin-dashboard.card>

    @forelse ($suppliersTab['connections'] as $connection)
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-display text-lg font-black text-neo-ink">{{ $connection['name'] }}</h2>
                    <p class="mt-1 text-xs font-bold text-brand-muted">
                        {{ $providerLabels[$connection['provider']] ?? strtoupper($connection['provider']) }}
                        · Kurs 1 {{ $connection['currency'] }} = {{ $connection['exchange_rate_formatted'] }}
                        · {{ $connection['catalog_items_count'] }} SKU di katalog
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-admin-dashboard.badge :tone="$connection['is_active'] ? 'emerald' : 'slate'" dot>
                        {{ $connection['is_active'] ? 'Aktif' : 'Nonaktif' }}
                    </x-admin-dashboard.badge>
                    @unless ($connection['currency_confirmed'])
                        <x-admin-dashboard.badge tone="amber">Mata uang belum dikonfirmasi</x-admin-dashboard.badge>
                    @endunless
                    @if ($connection['low_balance'])
                        <x-admin-dashboard.badge tone="rose">Saldo menipis</x-admin-dashboard.badge>
                    @endif
                </div>
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2">
                <x-admin-dashboard.mini-stat label="Saldo" :value="$connection['balance']" />
                <x-admin-dashboard.mini-stat label="Ditahan" :value="$connection['held_balance']" />
                <x-admin-dashboard.mini-stat label="Bisa dipakai" :value="$connection['available_balance']" tone="cyan" />
            </div>

            <p class="mt-2 break-words text-xs font-bold text-brand-muted">
                @if ($connection['available_balance_idr'])
                    ≈ {{ $connection['available_balance_idr'] }} bisa dipakai ·
                @endif
                Sinkron terakhir: {{ $connection['synced_human'] }}
                @if ($connection['fresh'])
                    <span class="text-neo-success-text">· data segar</span>
                @else
                    <span class="text-neo-warning-text">· perlu sinkron ulang</span>
                @endif
                · {{ $connection['sold_products_count'] }} produk sedang dijual
            </p>

            @if ($connection['low_balance'])
                <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">
                    Saldo supplier menipis. Isi saldo sebelum memproses pesanan supplier baru.
                </x-admin-dashboard.notice>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-admin-dashboard.button href="{{ route('admin.suppliers.catalog', $connection['id']) }}" variant="primary" size="sm">Buka katalog & jual</x-admin-dashboard.button>
                <form method="POST" action="{{ route('admin.suppliers.sync', $connection['id']) }}">
                    @csrf
                    <input type="hidden" name="context" value="suppliers">
                    <input type="hidden" name="context_id" value="{{ $connection['id'] }}">
                    <x-admin-dashboard.button type="submit" variant="secondary" size="sm">Sinkronkan sekarang</x-admin-dashboard.button>
                </form>
            </div>

            <details class="mt-3" @if ($failedConnectionId === $connection['id']) open @endif>
                <summary class="cursor-pointer font-bold">Edit koneksi</summary>
                <div class="mt-4">
                    @include('admin.suppliers.connection-form', ['connection' => $connection, 'repopulate' => $failedConnectionId === $connection['id']])
                </div>
            </details>
        </x-admin-dashboard.card>
    @empty
        <x-admin-dashboard.empty-state title="Belum ada supplier" body="Tekan Tambah supplier untuk membuat koneksi pertama." />
    @endforelse

    <div>{{ $suppliersTab['connections']->links() }}</div>

    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="min-w-0" aria-labelledby="supplier-products-title">
        <h2 id="supplier-products-title" class="font-display text-base font-black uppercase text-neo-ink">Produk dari supplier</h2>
        <p class="mt-1 text-xs font-bold text-brand-muted">Hanya produk yang tersambung ke SKU supplier yang tampil di sini. Nama, deskripsi, gambar, dan status publik tetap dikelola di tab Produk.</p>

        <div class="mt-3 grid gap-3">
            @forelse ($suppliersTab['products'] as $row)
                <article class="rounded-[1rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="break-words font-display text-base font-black text-neo-ink">{{ $row['name'] }}</p>
                            <p class="mt-1 text-xs font-bold text-brand-muted">
                                Harga jual {{ $row['price_formatted'] }}
                                @if ($row['cost_formatted'])
                                    · Modal {{ $row['cost_formatted'] }}
                                @endif
                                @if ($row['profit_formatted'])
                                    · Untung {{ $row['profit_formatted'] }}{{ $row['profit_percent_formatted'] ? ' ('.$row['profit_percent_formatted'].')' : '' }}
                                @endif
                            </p>
                            @if ($row['supplier_name'])
                                <p class="mt-1 text-[11px] font-bold text-brand-muted">
                                    Sumber: {{ $row['supplier_name'] }}
                                    @if ($row['sku']) · SKU {{ $row['sku'] }} @endif
                                    @if ($row['source_count'] > 1) · {{ $row['source_count'] }} sumber @endif
                                </p>
                            @endif
                        </div>
                        <x-admin-dashboard.badge :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-admin-dashboard.badge>
                    </div>
                    <div class="mt-3">
                        <x-admin-dashboard.button href="{{ route('admin.products.suppliers', $row['id']) }}" variant="primary" size="sm">Atur harga & status</x-admin-dashboard.button>
                    </div>
                </article>
            @empty
                <x-admin-dashboard.empty-state title="Belum ada produk dari supplier" body="Buka katalog supplier, lalu tekan Jual produk ini pada SKU yang ingin kamu jual." />
            @endforelse
        </div>

        <div class="mt-3">{{ $suppliersTab['products']->links() }}</div>
    </x-admin-dashboard.card>
</section>
