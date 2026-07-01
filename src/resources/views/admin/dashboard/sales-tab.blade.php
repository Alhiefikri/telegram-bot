            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PENJUALAN TAB (Pesanan)                            ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'penjualan'" x-data="{ salesFilter: 'all' }" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-bold uppercase tracking-wider" style="color: #050505;">🛒 Penjualan Saya</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Order masuk dan diproses</p>
                </div>

                {{-- Filter pills --}}
                <div class="overflow-x-auto pb-1" aria-label="Filter penjualan">
                    <div class="flex min-w-max items-center gap-2">
                        @foreach ($salesFilters as $filter)
                            <button
                                type="button"
                                x-on:click="salesFilter = @js($filter['key'])"
                                x-bind:class="salesFilter === @js($filter['key']) ? 'filter-pill-active' : ''"
                                x-bind:aria-pressed="salesFilter === @js($filter['key'])"
                                class="filter-pill"
                                data-sales-filter="{{ $filter['key'] }}"
                            >
                                <span>{{ $filter['label'] }}</span>
                                <span class="filter-pill-count">{{ $filter['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Order cards --}}
                <div class="space-y-3">
                    @forelse ($orders as $order)
                        <article
                            class="order-card"
                            data-order-status="{{ $order['order_status'] }}"
                            data-order-filter-groups="{{ implode(' ', $order['filter_groups']) }}"
                            x-show="salesFilter === 'all' || $el.dataset.orderFilterGroups.split(' ').includes(salesFilter)"
                        >
                            {{-- Invoice + status --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[10px] font-bold uppercase tracking-wider" style="color: #64748b;">{{ $order['invoice_number'] }}</p>
                                    <p class="mt-1 truncate font-display text-base font-bold" style="color: #050505;">{{ $order['product_name'] }}</p>
                                </div>
                                @php
                                    $orderBadgeBg = match($order['order_status_tone']) {
                                        'emerald' => '#e8ffd4',
                                        'amber' => '#fef9c3',
                                        'blue' => '#d4f6fb',
                                        'rose' => '#fecaca',
                                        default => '#f1f5f9',
                                    };
                                    $orderBadgeColor = match($order['order_status_tone']) {
                                        'emerald' => '#166534',
                                        'amber' => '#92400e',
                                        'blue' => '#0e7490',
                                        'rose' => '#dc2626',
                                        default => '#64748b',
                                    };
                                @endphp
                                <span class="neo-badge" style="background: {{ $orderBadgeBg }}; color: {{ $orderBadgeColor }};">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    {{ $order['order_status_label'] }}
                                </span>
                            </div>

                            {{-- Price + Earning --}}
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <div class="rounded-lg p-2.5" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Harga</p>
                                    <p class="mt-0.5 break-words font-display text-sm font-bold" style="color: #050505;">{{ $order['price'] }}</p>
                                </div>
                                <div class="rounded-lg p-2.5" style="background: #e8ffd4; border: 1px solid #7BD10A;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #166534;">Earning</p>
                                    <p class="mt-0.5 break-words font-display text-sm font-bold" style="color: #050505;">{{ $order['earning'] }}</p>
                                </div>
                            </div>

                            {{-- Customer + Date --}}
                            <div class="mt-3 grid grid-cols-2 gap-3 border-t-2 pt-3" style="border-color: #e2e8f0;">
                                <div class="min-w-0">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Pembeli</p>
                                    <p class="mt-0.5 truncate text-sm font-bold" style="color: #050505;">{{ $order['customer'] }}</p>
                                </div>
                                <div class="min-w-0 text-right">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Tanggal</p>
                                    <p class="mt-0.5 text-sm font-bold" style="color: #050505;">{{ $order['created_at_label'] }}</p>
                                </div>
                            </div>

                            {{-- Payment badge + item count --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @php
                                    $payBadgeBg = match($order['payment_status_tone']) {
                                        'emerald' => '#e8ffd4',
                                        'amber' => '#fef9c3',
                                        'rose' => '#fecaca',
                                        default => '#f1f5f9',
                                    };
                                    $payBadgeColor = match($order['payment_status_tone']) {
                                        'emerald' => '#166534',
                                        'amber' => '#92400e',
                                        'rose' => '#dc2626',
                                        default => '#64748b',
                                    };
                                @endphp
                                <span class="neo-badge" style="background: {{ $payBadgeBg }}; color: {{ $payBadgeColor }};">
                                    {{ $order['payment_status_label'] }}
                                </span>
                                <span class="text-xs font-bold" style="color: #64748b;">{{ $order['item_count'] }} item</span>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl p-6 text-center" style="background: #fff; border: 2px dashed #050505;">
                            <p class="font-display text-sm font-bold" style="color: #64748b;">Belum ada order.</p>
                        </div>
                    @endforelse
                </div>
            </div>
