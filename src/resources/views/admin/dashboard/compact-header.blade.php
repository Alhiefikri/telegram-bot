@php
    $internalTabLabels = [
        'produk' => 'Produk',
        'penjualan' => 'Pesanan',
        'broadcast' => 'Siaran',
        'telegram-channel' => 'Store Channel',
        'riwayat' => 'Riwayat KlikQRIS',
        'withdraw' => 'Withdraw Order Status',
        'funnel' => 'Funnel Storefront',
        'balance-issues' => 'Kompensasi Top-up',
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
        <x-theme-toggle class="h-10 w-10 shrink-0 rounded-lg border-2 border-neo-ink bg-white text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    </div>
</x-admin-dashboard.card>
