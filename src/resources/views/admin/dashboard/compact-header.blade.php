@php
    $internalTabLabels = [
        'produk' => 'Produk',
        'penjualan' => 'Pesanan',
        'broadcast' => 'Siaran',
        'telegram-channel' => 'Store Channel',
    ];
    $internalTabLabel = $internalTabLabels[$navigation['activeTab']] ?? 'Dashboard';
@endphp
<x-admin-dashboard.card
    as="header"
    padding="sm"
    shadow="sm"
    role="banner"
    aria-label="Navigasi section admin"
    class="admin-internal-top-bar sticky top-3 z-30 flex items-center justify-between gap-3 rounded-[1.125rem]"
>
    <div class="min-w-0">
        <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">LHIE STORE ADMIN</p>
        <h1 class="mt-0.5 truncate font-display text-lg font-black uppercase tracking-wide text-neo-ink">
            {{ $internalTabLabel }}
        </h1>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <x-admin-dashboard.badge :tone="$storeStatus['tone']" dot>
            {{ $storeStatus['label'] }}
        </x-admin-dashboard.badge>
        <x-admin-dashboard.badge :tone="$checkoutMaintenance['tone']" dot>
            {{ $checkoutMaintenance['status_badge']['label'] }}
        </x-admin-dashboard.badge>
    </div>
</x-admin-dashboard.card>
