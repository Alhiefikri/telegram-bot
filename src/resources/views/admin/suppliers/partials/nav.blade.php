@php
    $supplier = $supplier ?? null;
    $isDashboard = request()->routeIs('admin.dashboard');
    $isCatalog = request()->routeIs('admin.suppliers.catalog');
    $isPurchases = request()->routeIs('admin.suppliers.purchases');
    $currentPage = fn (bool $current): string => $current ? 'page' : 'false';
@endphp
<nav class="flex flex-wrap items-center gap-2" aria-label="Navigasi supplier">
    <x-admin-dashboard.button
        href="{{ route('admin.dashboard', ['tab' => 'suppliers']) }}"
        variant="{{ $isDashboard ? 'primary' : 'neutral' }}"
        size="sm"
        aria-current="{{ $currentPage($isDashboard) }}"
    >
        Dashboard Supplier
    </x-admin-dashboard.button>
    @if ($supplier instanceof \App\Models\Supplier)
        <x-admin-dashboard.button
            href="{{ route('admin.suppliers.catalog', $supplier) }}"
            variant="{{ $isCatalog ? 'primary' : 'neutral' }}"
            size="sm"
            aria-current="{{ $currentPage($isCatalog) }}"
        >
            Katalog & jual
        </x-admin-dashboard.button>
    @endif
    <x-admin-dashboard.button
        href="{{ route('admin.suppliers.purchases') }}"
        variant="{{ $isPurchases ? 'primary' : 'neutral' }}"
        size="sm"
        aria-current="{{ $currentPage($isPurchases) }}"
    >
        Monitor pembelian
    </x-admin-dashboard.button>
</nav>
