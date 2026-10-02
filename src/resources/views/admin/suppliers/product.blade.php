@extends('admin.suppliers.layout')
@section('title', 'Produk & harga · '.$product->name)
@section('content')
@php
    $hasActiveSource = $sources->contains(fn ($source) => (bool) $source->is_active);
    $priceReady = $economy !== null && ($economy['retail_idr'] ?? 0) > 0 && ($economy['tier_schedule_valid'] ?? true) !== false;
    $firstItemId = $primarySource?->supplier_catalog_item_id;
@endphp

<x-admin-dashboard.card padding="compact" shadow="sm" class="min-w-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="break-words font-display text-lg font-black uppercase text-neo-ink">{{ $product->name }}</h2>
                <x-admin-dashboard.badge :tone="$product->is_active ? 'emerald' : 'slate'" dot>{{ $product->is_active ? 'Tayang' : 'Nonaktif' }}</x-admin-dashboard.badge>
            </div>
            <p class="mt-2 text-sm font-bold text-brand-muted" data-supplier-current-price>
                Harga jual sekarang <span class="font-display text-base text-neo-ink">Rp{{ number_format((int) $product->price_amount, 0, ',', '.') }}</span>
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            @if ($supplier)
                <x-admin-dashboard.button href="{{ route('admin.suppliers.catalog', $supplier) }}" variant="neutral" size="sm">Kembali ke katalog</x-admin-dashboard.button>
            @endif
            <x-admin-dashboard.button href="{{ $storefrontUrl }}" variant="neutral" size="sm">Lihat di toko</x-admin-dashboard.button>
            @if ($supplier)
                <form method="POST" action="{{ route('admin.suppliers.sync', $supplier) }}">
                    @csrf
                    <input type="hidden" name="context" value="product">
                    <input type="hidden" name="context_id" value="{{ $product->id }}">
                    <x-admin-dashboard.button type="submit" variant="secondary" size="sm">Sinkronkan sekarang</x-admin-dashboard.button>
                </form>
            @endif
        </div>
    </div>
</x-admin-dashboard.card>

<x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="min-w-0" aria-labelledby="supplier-steps-title">
    <h2 id="supplier-steps-title" class="font-display text-base font-black uppercase text-neo-ink">Langkah agar produk siap dijual</h2>
    <ol class="mt-3 grid gap-2">
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-xs">
            <span class="flex items-center gap-2 text-sm font-bold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border-2 border-neo-ink text-xs font-black {{ $hasActiveSource ? 'bg-neo-green' : 'bg-white' }}">{{ $hasActiveSource ? '✓' : '1' }}</span>
                Sumber supplier terhubung & aktif
            </span>
            <span class="flex items-center gap-2">
                <x-admin-dashboard.badge :tone="$hasActiveSource ? 'emerald' : 'amber'">{{ $hasActiveSource ? 'Siap' : 'Belum' }}</x-admin-dashboard.badge>
                <a class="text-xs font-black underline" href="#supplier-sources">Atur sumber</a>
            </span>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-xs">
            <span class="flex items-center gap-2 text-sm font-bold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border-2 border-neo-ink text-xs font-black {{ $priceReady ? 'bg-neo-green' : 'bg-white' }}">{{ $priceReady ? '✓' : '2' }}</span>
                Harga jual ditetapkan
            </span>
            <span class="flex items-center gap-2">
                <x-admin-dashboard.badge :tone="$priceReady ? 'emerald' : 'amber'">{{ $priceReady ? 'Siap' : 'Belum' }}</x-admin-dashboard.badge>
                <a class="text-xs font-black underline" href="#supplier-pricing">Atur harga</a>
            </span>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-xs">
            <span class="flex items-center gap-2 text-sm font-bold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border-2 border-neo-ink text-xs font-black {{ $product->is_active ? 'bg-neo-green' : 'bg-white' }}">{{ $product->is_active ? '✓' : '3' }}</span>
                Produk tayang di katalog
            </span>
            <form method="POST" action="{{ route('admin.products.visibility.update', $product) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}">
                <input type="hidden" name="return" value="supplier">
                <x-admin-dashboard.button type="submit" variant="{{ $product->is_active ? 'neutral' : 'primary' }}" size="sm">
                    {{ $product->is_active ? 'Pause produk' : 'Tayangkan produk' }}
                </x-admin-dashboard.button>
            </form>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-[1rem] border-[3px] border-dashed border-neo-ink/40 bg-white/70 p-3">
            <span class="text-sm font-bold">Lengkapi foto, deskripsi, kategori, dan family (opsional)</span>
            <span class="flex flex-wrap items-center gap-2">
                <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'produk', 'edit_product' => $product->id])" variant="neutral" size="sm">Edit produk lengkap</x-admin-dashboard.button>
                <a class="text-xs font-black underline" href="#supplier-family">Atur family</a>
            </span>
        </li>
    </ol>
</x-admin-dashboard.card>

<x-admin-dashboard.card as="section" id="supplier-family" padding="compact" shadow="sm" class="min-w-0 scroll-mt-24" aria-labelledby="supplier-family-title">
    <h2 id="supplier-family-title" class="font-display text-base font-black uppercase text-neo-ink">Katalog & family (opsional)</h2>
    <p class="mt-1 text-xs font-bold text-brand-muted">
        Kategori: <span class="font-black text-neo-ink">{{ $product->category?->name ?? 'Tanpa kategori' }}</span>
        · Family: <span class="font-black text-neo-ink">{{ $product->family?->name ?? 'Belum masuk family' }}</span>
    </p>

    <form method="POST" action="{{ route('admin.products.family.update', $product) }}" class="mt-3 grid gap-3">
        @csrf
        @method('PATCH')
        <input type="hidden" name="return" value="supplier">

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-family-select">Product Family</x-admin-dashboard.field-label>
                <x-admin-dashboard.select id="supplier-family-select" name="product_family_id">
                    <option value="">— Tanpa family —</option>
                    @foreach ($familyOptions as $familyOption)
                        <option value="{{ $familyOption->id }}" @selected((int) old('product_family_id', (int) $product->product_family_id) === (int) $familyOption->id)>{{ $familyOption->name }}</option>
                    @endforeach
                </x-admin-dashboard.select>
                @error('product_family_id', 'productFamilyMembership') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
            </div>
            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-family-order">Urutan option</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="supplier-family-order" name="family_sort_order" type="number" min="0" max="999999" :value="old('family_sort_order', (int) $product->family_sort_order)" />
                @error('family_sort_order', 'productFamilyMembership') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
            </div>
            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-family-label">Label option (opsional)</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="supplier-family-label" name="family_option_label" maxlength="160" :value="old('family_option_label', $product->family_option_label ?? '')" />
                @error('family_option_label', 'productFamilyMembership') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
            </div>
        </div>

        <p class="text-[10px] font-bold text-neo-ink/60">Semua anggota family harus memakai kategori yang sama. Kalau kategorinya berbeda, simpan akan ditolak sampai kategori disamakan lewat Edit produk.</p>

        <x-admin-dashboard.button type="submit" variant="neutral" size="sm" class="justify-self-start">Simpan family</x-admin-dashboard.button>
    </form>
</x-admin-dashboard.card>

@if ($economy !== null)
    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="min-w-0" aria-labelledby="supplier-economy-title">
        <h2 id="supplier-economy-title" class="font-display text-base font-black uppercase text-neo-ink">Ringkasan untung</h2>
        <p class="mt-1 text-xs font-bold text-brand-muted">Dihitung dari sumber utama: {{ $economy['source_label'] ?: 'SKU supplier' }}.</p>
        <div class="mt-3 grid grid-cols-3 gap-2">
            <x-admin-dashboard.mini-stat label="Modal" :value="$economy['cost_formatted'] ?? 'Belum diketahui'" tone="amber" />
            <x-admin-dashboard.mini-stat label="Harga jual" :value="$economy['retail_formatted'] ?? '—'" tone="emerald" />
            <x-admin-dashboard.mini-stat label="Untung / unit" :value="trim(($economy['profit_formatted'] ?? '—').' '.($economy['profit_percent_formatted'] ? '('.$economy['profit_percent_formatted'].')' : ''))" tone="cyan" />
        </div>
        @if ($economy['cost_idr'] === null)
            <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">Modal belum diketahui karena harga SKU belum tersedia. Sinkronkan supplier, lalu muat ulang halaman ini.</x-admin-dashboard.notice>
        @elseif (! $economy['supplier_active'])
            <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">Koneksi supplier belum aktif atau mata uangnya belum dikonfirmasi, jadi produk ini belum bisa dijual otomatis.</x-admin-dashboard.notice>
        @elseif (! $economy['fresh'])
            <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">Data supplier sudah kedaluwarsa. Sinkronkan ulang agar kapasitas dan harga terbaru dipakai.</x-admin-dashboard.notice>
        @endif
        @if (($economy['tier_schedule_valid'] ?? true) === false)
            <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">Harga otomatis saat ini membuat harga bertingkat tidak valid. Sesuaikan tier kuantitas atau ubah aturan harga.</x-admin-dashboard.notice>
        @endif
    </x-admin-dashboard.card>
@endif

<x-admin-dashboard.card as="section" id="supplier-sources" padding="compact" shadow="sm" class="min-w-0 scroll-mt-24" aria-labelledby="supplier-sources-title">
    <h2 id="supplier-sources-title" class="font-display text-base font-black uppercase text-neo-ink">Sumber SKU supplier</h2>
    <p class="mt-1 text-xs font-bold text-brand-muted">Kalau ada beberapa sumber, yang angkanya paling kecil dipakai lebih dulu.</p>

    <div class="mt-3 grid gap-3">
        @forelse ($sources as $source)
            @php
                $item = $source->catalogItem;
                $sourceSupplier = $item?->supplier;
            @endphp
            <article class="rounded-[1rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="break-words font-display text-sm font-black text-neo-ink">{{ $sourceSupplier?->name ?? 'Supplier dihapus' }} · {{ $item?->name ?? 'SKU dihapus' }}</p>
                        <p class="mt-1 text-xs font-bold text-brand-muted">
                            SKU {{ $item?->external_id ?? '—' }} · prioritas {{ $source->priority }} · {{ $source->is_active ? 'sedang dipakai' : 'tidak dipakai' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($sourceSupplier && ! $sourceSupplier->is_active)
                            <x-admin-dashboard.badge tone="slate">Koneksi nonaktif</x-admin-dashboard.badge>
                        @endif
                        @if ($sourceSupplier && ! $sourceSupplier->currency_confirmed)
                            <x-admin-dashboard.badge tone="amber">Mata uang belum dikonfirmasi</x-admin-dashboard.badge>
                        @endif
                        @if ($sourceSupplier && ! ($sourceSupplier->sync_expires_at?->isFuture() ?? false))
                            <x-admin-dashboard.badge tone="amber">Perlu sinkron</x-admin-dashboard.badge>
                        @endif
                    </div>
                </div>

                @if ($item)
                    <form method="POST" action="{{ route('admin.suppliers.map', $item) }}" class="mt-3 grid gap-2">
                        @csrf
                        <input type="hidden" name="supplier_version" value="{{ $sourceSupplier?->version ?? 0 }}">
                        <input type="hidden" name="version" value="{{ $source->version }}">
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <input type="hidden" name="context" value="product">
                        <input type="hidden" name="context_id" value="{{ $product->id }}">

                        <div class="flex flex-wrap items-end gap-3">
                            <div class="grid gap-1">
                                <x-admin-dashboard.field-label for="source-priority-{{ $source->id }}">Prioritas</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input id="source-priority-{{ $source->id }}" name="priority" type="number" min="0" max="10000" class="w-28" value="{{ $source->priority }}" required />
                            </div>
                            <div class="grow">
                                <input type="hidden" name="is_active" value="0">
                                <label class="flex min-h-12 items-center gap-3 text-sm font-bold">
                                    <input type="checkbox" name="is_active" value="1" @checked($source->is_active) @disabled($item->requires_input || ! $item->orderable) class="h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream">
                                    <span>Pakai sumber ini untuk dijual</span>
                                </label>
                            </div>
                        </div>

                        @if ($item->requires_input || ! $item->orderable)
                            <p class="text-[11px] font-bold text-neo-warning-text">SKU ini tidak mendukung pengiriman instan, jadi belum bisa diaktifkan.</p>
                        @endif

                        <label class="flex items-start gap-3 text-xs font-bold text-brand-muted">
                            <input type="checkbox" name="equivalent_confirmed" value="1" required class="mt-0.5 h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream">
                            <span>Saya sudah memastikan SKU ini masih setara dengan produk toko.</span>
                        </label>

                        <x-admin-dashboard.button type="submit" variant="neutral" size="sm" class="justify-self-start">Simpan sumber</x-admin-dashboard.button>
                    </form>
                @endif
            </article>
        @empty
            <x-admin-dashboard.empty-state title="Belum ada sumber supplier" body="Buka katalog supplier, lalu tekan Jual produk ini untuk menghubungkan SKU." />
        @endforelse
    </div>
</x-admin-dashboard.card>

<x-admin-dashboard.card as="section" id="supplier-pricing" padding="compact" shadow="sm" class="min-w-0 scroll-mt-24" aria-labelledby="supplier-pricing-title">
    <h2 id="supplier-pricing-title" class="font-display text-base font-black uppercase text-neo-ink">Atur harga</h2>
    <p class="mt-1 text-xs font-bold text-brand-muted">Pilih cara menentukan harga jual. Untuk mode otomatis, harga dasar IDR diperbarui mengikuti harga supplier setiap sinkron; snapshot pesanan yang sudah ada tidak berubah.</p>

    <div
        class="mt-3"
        x-data="supplierPricingCalculator({
            previewUrl: @js(route('admin.products.supplier-pricing.preview', $product)),
            csrfToken: @js(csrf_token()),
            initial: @js([
                'mode' => $policyValues['mode'],
                'markup_basis_points' => $policyValues['markup_basis_points'],
                'profit_idr' => $policyValues['profit_idr'],
                'minimum_profit_idr' => $policyValues['minimum_profit_idr'],
                'buffer_idr' => $policyValues['buffer_idr'],
                'rounding_idr' => $policyValues['rounding_idr'],
                'version' => $policy?->version ?? 0,
                'catalog_item_id' => $firstItemId,
                'current_retail_idr' => (int) $product->price_amount,
                'preview' => $economy,
            ]),
        })"
    >
        <form method="POST" action="{{ route('admin.products.supplier-pricing', $product) }}" class="grid gap-3">
            @csrf
            <input type="hidden" name="version" value="{{ $policy?->version ?? 0 }}">
            <input type="hidden" name="context" value="product">
            <input type="hidden" name="context_id" value="{{ $product->id }}">
            <input type="hidden" name="markup_basis_points" value="{{ $policyValues['markup_basis_points'] }}" x-bind:value="basisPoints">

            <fieldset class="grid gap-2">
                <legend class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Cara menentukan harga jual</legend>
                @foreach ([
                    'manual' => ['Saya isi harga jual sendiri', 'Harga tidak berubah otomatis. Ubah harga jual dari form produk.'],
                    'percent' => ['Tambah untung persen', 'Harga jual = modal + untung persen.'],
                    'fixed' => ['Tambah untung nominal', 'Harga jual = modal + untung Rupiah.'],
                    'hybrid' => ['Persentase dengan minimum Rupiah', 'Pakai untung persen, tapi jangan di bawah nominal minimum per unit.'],
                ] as $modeValue => [$modeLabel, $modeHint])
                    <label class="cursor-pointer rounded-[1rem] border-[3px] border-neo-ink p-3 shadow-neo-xs transition" x-bind:class="mode === '{{ $modeValue }}' ? 'bg-[#fee648]' : 'bg-white'">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="mode" value="{{ $modeValue }}" x-model="mode" x-on:change="changed()" class="mt-1 h-5 w-5" @checked($policyValues['mode'] === $modeValue)>
                            <span>
                                <span class="block font-display text-sm font-black uppercase">{{ $modeLabel }}</span>
                                <span class="mt-1 block text-xs font-bold text-brand-muted">{{ $modeHint }}</span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1" x-show="mode === 'percent' || mode === 'hybrid'">
                    <x-admin-dashboard.field-label for="supplier-pricing-percent">Untung (%)</x-admin-dashboard.field-label>
                    <input
                        id="supplier-pricing-percent"
                        type="number"
                        min="0"
                        max="1000"
                        step="0.1"
                        inputmode="decimal"
                        class="min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus"
                        x-model.number="percent"
                        x-on:input="changed()"
                    >
                    <p class="text-[10px] font-bold text-neo-ink/60">Contoh: 20 artinya untung 20% dari modal.</p>
                </div>

                <div class="grid gap-1" x-show="mode === 'fixed' || mode === 'hybrid'">
                    <x-admin-dashboard.field-label for="supplier-pricing-profit"><span x-text="mode === 'hybrid' ? 'Minimum untung per unit (Rp)' : 'Untung per unit (Rp)'"></span></x-admin-dashboard.field-label>
                    <input
                        id="supplier-pricing-profit"
                        name="profit_idr"
                        type="number"
                        min="0"
                        max="1000000000"
                        inputmode="numeric"
                        class="min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus"
                        x-model.number="profitIdr"
                        x-on:input="changed()"
                    >
                </div>

                @if ($sources->isNotEmpty())
                    <div class="grid gap-1 sm:col-span-2">
                        <x-admin-dashboard.field-label for="supplier-pricing-sku">SKU untuk contoh perhitungan</x-admin-dashboard.field-label>
                        <x-admin-dashboard.select id="supplier-pricing-sku" name="catalog_item_id" x-model="catalogItemId" x-on:change="changed()">
                            @foreach ($sources as $source)
                                <option value="{{ $source->supplier_catalog_item_id }}">{{ $source->catalogItem?->supplier?->name }} · {{ $source->catalogItem?->name }}</option>
                            @endforeach
                        </x-admin-dashboard.select>
                    </div>
                @endif
            </div>

            <details class="rounded-[1rem] border-[3px] border-dashed border-neo-ink/40 bg-white/70 p-3">
                <summary class="cursor-pointer font-bold">Pengaturan lanjutan</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <x-admin-dashboard.field-label for="supplier-pricing-rounding">Pembulatan harga jual</x-admin-dashboard.field-label>
                        <x-admin-dashboard.select id="supplier-pricing-rounding" name="rounding_idr" x-model.number="roundingIdr" x-on:change="changed()">
                            @foreach ([1 => 'Tanpa pembulatan', 100 => 'Ke atas ke Rp100', 500 => 'Ke atas ke Rp500', 1000 => 'Ke atas ke Rp1.000'] as $value => $label)
                                <option value="{{ $value }}" @selected($policyValues['rounding_idr'] === $value)>{{ $label }}</option>
                            @endforeach
                        </x-admin-dashboard.select>
                    </div>
                    <div class="grid gap-1">
                        <x-admin-dashboard.field-label for="supplier-pricing-buffer">Buffer biaya per unit (Rp)</x-admin-dashboard.field-label>
                        <input
                            id="supplier-pricing-buffer"
                            name="buffer_idr"
                            type="number"
                            min="0"
                            max="1000000000"
                            inputmode="numeric"
                            class="min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus"
                            x-model.number="bufferIdr"
                            x-on:input="changed()"
                        >
                        <p class="text-[10px] font-bold text-neo-ink/60">Ditambahkan ke modal sebelum untung dihitung, misalnya untuk biaya operasional.</p>
                    </div>
                    <div class="grid gap-1">
                        <x-admin-dashboard.field-label for="supplier-pricing-minimum">Batas untung minimum (Rp)</x-admin-dashboard.field-label>
                        <input
                            id="supplier-pricing-minimum"
                            name="minimum_profit_idr"
                            type="number"
                            min="0"
                            max="1000000000"
                            inputmode="numeric"
                            class="min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus"
                            x-model.number="minimumProfitIdr"
                            x-on:input="changed()"
                        >
                        <p class="text-[10px] font-bold text-neo-ink/60">Tidak mengubah harga jual. Kalau untung di bawah angka ini, produk tidak dijual otomatis.</p>
                    </div>
                </div>
            </details>

            <div class="rounded-[1rem] border-[3px] border-neo-ink bg-neo-success-soft p-3" x-show="! isManual" data-supplier-preview>
                <p class="text-[10px] font-black uppercase tracking-wide text-brand-muted">Contoh perhitungan</p>
                <p class="mt-1 text-xs font-bold text-neo-ink" x-show="previewLoading">Menghitung...</p>
                <p class="mt-1 text-xs font-bold text-neo-danger-text" x-show="previewError" x-text="previewError"></p>
                <div class="mt-2 grid grid-cols-3 gap-2" x-show="! previewLoading && preview">
                    <div>
                        <p class="text-[10px] font-black uppercase text-brand-muted">Modal</p>
                        <p class="font-display text-sm font-black" x-text="formatIdr(preview?.cost_idr)"></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-brand-muted">Harga jual</p>
                        <p class="font-display text-sm font-black" data-supplier-preview-retail x-text="formatIdr(preview?.retail_idr)"></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-brand-muted">Untung / unit</p>
                        <p class="font-display text-sm font-black">
                            <span x-text="formatIdr(preview?.profit_idr)"></span>
                            <span x-show="preview?.profit_percent_basis_points !== null && preview?.profit_percent_basis_points !== undefined" x-text="' (' + formatPercent(preview?.profit_percent_basis_points) + ')'"></span>
                        </p>
                    </div>
                </div>
                <p class="mt-2 text-xs font-bold text-neo-warning-text" x-show="preview && preview.tier_schedule_valid === false">
                    Harga otomatis ini membuat harga bertingkat tidak valid. Sesuaikan harga bertingkat di form produk, atau ubah aturan harga.
                </p>
            </div>

            <div class="rounded-[1rem] border-[3px] border-neo-ink bg-white p-3" x-show="isManual">
                <p class="text-xs font-bold text-brand-muted">
                    Mode manual: harga jual tidak berubah otomatis. Harga jual sekarang
                    <span class="font-black text-neo-ink">Rp{{ number_format((int) $product->price_amount, 0, ',', '.') }}</span>.
                    Ubah lewat <a class="underline" href="{{ route('admin.dashboard', ['tab' => 'produk', 'edit_product' => $product->id]) }}">Edit produk</a>.
                </p>
            </div>

            <x-admin-dashboard.button type="submit" variant="primary" class="justify-self-start">Simpan aturan harga</x-admin-dashboard.button>
        </form>
    </div>
</x-admin-dashboard.card>
@endsection
