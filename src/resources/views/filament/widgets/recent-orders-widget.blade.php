<x-filament-widgets::widget>
<div class="min-w-0 overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-600">Pesanan terbaru</p>
            <h2 class="mt-2 font-['Space_Grotesk'] text-xl font-bold tracking-[-0.04em] text-slate-900">Aktivitas pesanan yang baru masuk</h2>
        </div>
    </div>

    <div class="mt-5">
        @if ($isEmpty)
            <x-filament.widgets.partials.empty-state
                title="Belum ada pesanan"
                description="Pesanan terbaru akan tampil di sini setelah pelanggan mulai checkout."
            />
        @else
            <div class="grid gap-3 xl:hidden">
                @foreach ($orders as $order)
                    <a href="{{ $order['url'] }}" class="block min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 transition hover:border-sky-200 hover:bg-sky-50/60">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-all text-sm font-semibold leading-5 text-slate-900">{{ $order['invoice_number'] }}</p>
                                <p class="mt-2 truncate text-sm font-medium text-slate-700">{{ $order['customer_name'] }}</p>
                                @if ($order['customer_handle'])
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $order['customer_handle'] }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 rounded-full bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">Buka</span>
                        </div>

                        <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-600">{{ $order['products'] }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <x-filament.widgets.partials.status-pill :label="$order['order_status']" :tone="$order['order_status_tone']" />
                            <x-filament.widgets.partials.status-pill :label="$order['payment_status']" :tone="$order['payment_status_tone']" />
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-semibold text-slate-900">{{ $order['total_amount'] }}</span>
                            <span class="text-slate-500">{{ $order['created_at'] }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto xl:block">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            <th class="py-3 pr-4">Invoice/ID</th>
                            <th class="py-3 pr-4">Customer</th>
                            <th class="py-3 pr-4">Produk</th>
                            <th class="py-3 pr-4">Total</th>
                            <th class="py-3 pr-4">Status</th>
                            <th class="py-3 pr-4">Waktu</th>
                            <th class="py-3 pr-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
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
                                        <x-filament.widgets.partials.status-pill :label="$order['order_status']" :tone="$order['order_status_tone']" />
                                        <x-filament.widgets.partials.status-pill :label="$order['payment_status']" :tone="$order['payment_status_tone']" />
                                    </div>
                                </td>
                                <td class="py-4 pr-4 text-slate-500">{{ $order['created_at'] }}</td>
                                <td class="py-4 pr-4">
                                    <a href="{{ $order['url'] }}" class="rounded-full bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-sky-700">Buka</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
</x-filament-widgets::widget>
