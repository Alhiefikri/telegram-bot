@extends('admin.suppliers.layout')
@section('title', 'Katalog supplier · '.$supplier->name)
@section('content')
@php
    $providerLabels = ['prodseller' => 'ProdSeller', 'csxstore' => 'CSxStore', 'arrs' => 'ARRS', 'warzone' => 'Warzone', 'aivault' => 'AI Vault Hub'];
    $statusChips = [
        'semua' => 'Semua',
        'belum-dijual' => 'Belum dijual',
        'sudah-dijual' => 'Sudah dijual',
        'instan' => 'Bisa instan',
        'perlu-input' => 'Perlu input manual',
    ];
    $chipUrl = function (string $status) use ($supplier, $filters): string {
        $query = ['supplier' => $supplier->getKey()];
        if ($filters['q'] !== '') {
            $query['q'] = $filters['q'];
        }
        if ($status !== 'semua') {
            $query['status'] = $status;
        }

        return route('admin.suppliers.catalog', $query);
    };
@endphp

<div x-data="supplierSellFlow({ searchUrl: @js(route('admin.products.search')) })">
    <x-admin-dashboard.card padding="compact" shadow="sm" class="min-w-0">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-brand-muted">{{ $providerLabels[$supplier->provider] ?? strtoupper($supplier->provider) }} · {{ $supplier->currency }} · Kurs Rp{{ number_format($supplier->exchange_rate_idr, 0, ',', '.') }}</p>
                <h2 class="mt-1 font-display text-lg font-black uppercase text-neo-ink">Pilih SKU yang mau dijual</h2>
                <p class="mt-2 text-xs font-bold leading-5 text-brand-muted">
                    Tekan <span class="text-neo-ink">Jual produk ini</span> pada SKU yang kamu mau. Kamu bisa membuat produk baru atau menghubungkannya ke produk yang sudah ada. Deskripsi supplier hanya catatan privat, tidak pernah dipublikasikan otomatis.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.suppliers.sync', $supplier) }}" class="shrink-0">
                @csrf
                <input type="hidden" name="context" value="catalog">
                <input type="hidden" name="context_id" value="{{ $supplier->id }}">
                <input type="hidden" name="return_q" value="{{ $filters['q'] }}">
                <input type="hidden" name="return_status" value="{{ $filters['status'] }}">
                <x-admin-dashboard.button type="submit" variant="secondary" size="sm">Sinkronkan sekarang</x-admin-dashboard.button>
            </form>
        </div>

        <form method="GET" action="{{ route('admin.suppliers.catalog', $supplier) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
            @if ($filters['status'] !== 'semua')
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif
            <x-admin-dashboard.input name="q" :value="$filters['q']" placeholder="Cari nama atau SKU supplier..." class="min-w-0 flex-1" aria-label="Cari SKU supplier" />
            <div class="flex gap-2">
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="min-h-12 px-4">Cari</x-admin-dashboard.button>
                @if ($filters['q'] !== '')
                    <x-admin-dashboard.button :href="$chipUrl($filters['status'])" variant="neutral" size="sm" class="min-h-12 px-4">Reset</x-admin-dashboard.button>
                @endif
            </div>
        </form>

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($statusChips as $key => $label)
                <a
                    href="{{ $chipUrl($key) }}"
                    @if ($filters['status'] === $key) aria-current="page" @endif
                    class="inline-flex items-center gap-1.5 rounded-full border-[3px] border-neo-ink px-3 py-1 text-[11px] font-black uppercase shadow-neo-xs {{ $filters['status'] === $key ? 'bg-neo-yellow' : 'bg-white' }}"
                >
                    {{ $label }}
                    <span class="rounded-full bg-neo-ink px-1.5 text-[10px] text-white">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </div>
    </x-admin-dashboard.card>

    @forelse ($items as $item)
        @php
            $costInfo = $economics[$item->id] ?? [];
            $sellable = $item->orderable && ! $item->requires_input;
            $payload = [
                'name' => $item->name,
                'external_id' => $item->external_id,
                'description' => $item->description,
                'cost_idr' => $costInfo['cost_idr'] ?? null,
                'cost' => $costInfo['cost_idr_formatted'] ?? null,
                'currency_cost' => $costInfo['cost_currency_formatted'] ?? null,
                'suggested_price' => $costInfo['suggested_retail_idr'] ?? null,
                'sellable' => $sellable,
                'import_url' => route('admin.suppliers.import', $item),
                'map_url' => route('admin.suppliers.map', $item),
            ];
        @endphp
        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="mt-3 min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="break-words font-display text-base font-black text-neo-ink">{{ $item->name }}</h3>
                    <p class="mt-1 text-xs font-bold text-brand-muted">SKU supplier {{ $item->external_id }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($item->requires_input)
                        <x-admin-dashboard.badge tone="amber">Butuh input manual</x-admin-dashboard.badge>
                    @elseif (! $item->orderable)
                        <x-admin-dashboard.badge tone="slate">Tidak bisa dipesan</x-admin-dashboard.badge>
                    @else
                        <x-admin-dashboard.badge tone="emerald">Bisa instan</x-admin-dashboard.badge>
                    @endif
                    @if ($item->sources->isNotEmpty())
                        <x-admin-dashboard.badge tone="purple">Sudah dijual</x-admin-dashboard.badge>
                    @endif
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <x-admin-dashboard.mini-stat label="Modal" :value="$costInfo['cost_idr_formatted'] ?? 'Belum diketahui'" tone="amber" />
                <x-admin-dashboard.mini-stat label="Harga supplier" :value="$costInfo['cost_currency_formatted'] ?? 'Tidak diketahui'" />
                <x-admin-dashboard.mini-stat label="Stok supplier" :value="$item->stock ?? 'Tidak diketahui'" tone="cyan" />
                <x-admin-dashboard.mini-stat label="Ditahan" :value="$item->reserved_quantity" />
            </div>

            @if ($item->description)
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-black uppercase tracking-wide text-brand-muted">Lihat deskripsi dari supplier (privat)</summary>
                    <p class="mt-2 whitespace-pre-wrap break-words text-xs font-semibold text-brand-muted">{{ $item->description }}</p>
                </details>
            @endif

            <p class="mt-3 break-words text-xs font-bold text-brand-muted">
                Dijual sebagai:
                @forelse ($item->sources as $source)
                    <a class="underline" href="{{ route('admin.products.suppliers', $source->product_id) }}">{{ $source->product?->name ?? 'Produk dihapus' }}</a>@if (! $loop->last), @endif
                @empty
                    <span>belum dijual ke pembeli</span>
                @endforelse
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                @if ($item->sources->isEmpty())
                    <button
                        type="button"
                        class="admin-dashboard-action inline-flex min-h-11 items-center justify-center gap-2 rounded-neo-2xl border-[3px] border-neo-ink bg-[#7BD10A] px-3 py-2 text-center text-xs font-black uppercase tracking-wide text-neo-ink shadow-neo-sm transition-all duration-150 hover:-translate-y-0.5 hover:shadow-neo-md active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
                        x-on:click="openFor(@js($payload))"
                    >Jual produk ini</button>
                @else
                    <x-admin-dashboard.button href="{{ route('admin.products.suppliers', $item->sources->first()->product_id) }}" variant="primary" size="sm">Kelola produk</x-admin-dashboard.button>
                @endif
            </div>
        </x-admin-dashboard.card>
    @empty
        <div class="mt-3">
            <x-admin-dashboard.empty-state
                :title="$filters['q'] !== '' ? 'Tidak ada SKU yang cocok dengan pencarian.' : 'Belum ada SKU di katalog supplier ini.'"
                body="Sinkronkan supplier dulu bila katalognya baru berubah."
            />
        </div>
    @endforelse

    <div class="mt-3">{{ $items->links() }}</div>

    @include('admin.suppliers.sell-modal', [
        'supplier' => $supplier,
        'returnQ' => $filters['q'],
        'returnStatus' => $filters['status'],
    ])
</div>
@endsection
