<x-filament-widgets::widget>
<x-lhie.dashboard-card>
    <x-lhie.section-header
        eyebrow="Pesanan terbaru"
        title="Aktivitas pesanan yang baru masuk"
        copy="Antrean transaksi masuk via Telegram di-update secara live."
    >
        <x-slot:aside>
            <a href="{{ $indexUrl }}" class="lhie-live-badge">
                <span class="lhie-live-badge__dot"></span>
                <span>Telegram live sync</span>
            </a>
        </x-slot:aside>
    </x-lhie.section-header>

    <div class="mt-5">
        @if ($isEmpty)
            <div class="lhie-empty-state">
                <div class="lhie-empty-state__icon">
                    <x-lhie.icon name="orders" class="h-5 w-5" />
                </div>

                <div class="space-y-1">
                    <p class="lhie-empty-state__title">Belum ada pesanan</p>
                    <p class="lhie-empty-state__description">Pesanan terbaru akan tampil di sini setelah pelanggan mulai checkout.</p>
                </div>
            </div>
        @else
            <div class="grid gap-3 xl:hidden">
                @foreach ($orders as $order)
                    <a href="{{ $order['url'] }}" class="lhie-action-card">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-all text-sm font-semibold leading-5 text-slate-900">{{ $order['invoice_number'] }}</p>
                                <p class="mt-2 truncate text-sm font-medium text-slate-700">{{ $order['customer_name'] }}</p>
                                @if ($order['customer_handle'])
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $order['customer_handle'] }}</p>
                                @endif
                            </div>
                            <span class="lhie-status-pill" data-tone="slate">Buka</span>
                        </div>

                        <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-600">{{ $order['products'] }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <x-lhie.status-pill :label="$order['order_status']" :tone="$order['order_status_tone']" />
                            <x-lhie.status-pill :label="$order['payment_status']" :tone="$order['payment_status_tone']" />
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-semibold text-slate-900">{{ $order['total_amount'] }}</span>
                            <span class="text-slate-500">{{ $order['created_at'] }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto xl:block">
                <table class="lhie-table">
                    <thead>
                        <tr>
                            <th class="py-3 pr-4">Invoice / ID</th>
                            <th class="py-3 pr-4">Pelanggan</th>
                            <th class="py-3 pr-4">Produk</th>
                            <th class="py-3 pr-4">Total</th>
                            <th class="py-3 pr-4">Status</th>
                            <th class="py-3 pr-4">Waktu</th>
                            <th class="py-3 pr-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr class="align-top text-sm">
                                <td class="max-w-[13rem] py-4 pr-4 font-semibold text-slate-900">
                                    <div class="break-all leading-5">{{ $order['invoice_number'] }}</div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="max-w-[10rem] truncate font-medium text-slate-900">{{ $order['customer_name'] }}</div>
                                    @if ($order['customer_handle'])
                                        <div class="mt-1 max-w-[10rem] truncate text-xs text-slate-500">{{ $order['customer_handle'] }}</div>
                                    @endif
                                </td>
                                <td class="max-w-[14rem] py-4 pr-4 text-slate-600">
                                    <div class="line-clamp-2">{{ $order['products'] }}</div>
                                </td>
                                <td class="py-4 pr-4 font-semibold text-slate-900">{{ $order['total_amount'] }}</td>
                                <td class="py-4 pr-4">
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-lhie.status-pill :label="$order['order_status']" :tone="$order['order_status_tone']" />
                                        <x-lhie.status-pill :label="$order['payment_status']" :tone="$order['payment_status_tone']" />
                                    </div>
                                </td>
                                <td class="py-4 pr-4 text-slate-500">{{ $order['created_at'] }}</td>
                                <td class="py-4 pr-4">
                                    <a href="{{ $order['url'] }}" class="rounded-full bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700">Buka</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-lhie.dashboard-card>
</x-filament-widgets::widget>
