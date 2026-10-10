            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PENJUALAN TAB (Pesanan)                            ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-sales" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">

                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-admin-ink">Penjualan Saya</h2>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Order masuk dan diproses</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ route('admin.manual-fulfillment-reviews.index') }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Manual Fulfillment Review</a>
                        <a href="{{ route('admin.telegram-outbound-effects.index') }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Manual Delivery Review</a>
                    </div>
                </div>

                {{-- Filter pills --}}
                <div class="overflow-x-auto pb-1" aria-label="Filter penjualan">
                    <div class="flex min-w-max items-center gap-2">
                        @foreach ($salesFilters as $filter)
                            <x-admin-dashboard.filter-pill :filter="$filter" />
                        @endforeach
                    </div>
                </div>

                {{-- Order cards --}}
                <div class="grid gap-3 md:grid-cols-2">
                    @forelse ($orders as $order)
                        <x-admin-dashboard.card
                            id="sales-proof-{{ $order['id'] }}"
                            data-admin-return-target
                            tabindex="-1"
                            as="article"
                            padding="compact"
                            shadow="sm"
                            class="min-w-0"
                            data-order-status="{{ $order['order_status'] }}"
                            data-order-filter-groups="{{ implode(' ', $order['filter_groups']) }}"
                        >
                            {{-- Invoice + status --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-medium text-admin-muted">{{ $order['invoice_number'] }}</p>
                                    <p class="mt-1 truncate text-base font-semibold text-admin-ink">{{ $order['product_name'] }}</p>
                                </div>
                                <x-admin-dashboard.badge :tone="$order['order_status_tone']" dot>
                                    {{ $order['order_status_label'] }}
                                </x-admin-dashboard.badge>
                            </div>

                            {{-- Price + Earning --}}
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <x-admin-dashboard.mini-stat label="Harga" :value="$order['price']" align="left" />
                                <x-admin-dashboard.mini-stat label="Earning" :value="$order['earning']" tone="emerald" align="left" />
                            </div>

                            {{-- Customer + Date --}}
                            <div class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-admin-muted">Pembeli</p>
                                    <p class="mt-0.5 truncate text-sm font-semibold text-admin-ink">{{ $order['customer'] }}</p>
                                </div>
                                <div class="min-w-0 text-right">
                                    <p class="text-xs font-medium text-admin-muted">Tanggal</p>
                                    <p class="mt-0.5 text-sm font-semibold text-admin-ink">{{ $order['created_at_label'] }}</p>
                                </div>
                            </div>

                            {{-- Payment badge + item count --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <x-admin-dashboard.badge :tone="$order['payment_status_tone']">
                                    {{ $order['payment_status_label'] }}
                                </x-admin-dashboard.badge>
                                <span class="text-xs font-medium text-admin-muted">{{ $order['item_count'] }} item</span>
                                <a href="{{ $order['detail_url'] }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Detail order</a>
                            </div>
                            @if ($order['supplier_delivery_status'] !== null)
                                <div class="mt-2 grid gap-1 rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-xs">
                                    <p class="text-admin-muted">Pengiriman pelanggan: <span class="font-semibold text-admin-ink">{{ $order['supplier_delivery_status'] }}</span></p>
                                    <p class="text-admin-muted">Pembelian supplier: <span class="font-semibold text-admin-ink">{{ $order['supplier_purchase_status'] }}</span></p>
                                </div>
                            @endif
                            @if ($order['payment_attention'])
                                <x-admin-dashboard.notice :tone="$order['payment_attention']['tone']" class="mt-3 text-xs">
                                    <span class="font-semibold">{{ $order['payment_attention']['label'] }}:</span> {{ $order['payment_attention']['message'] }}
                                </x-admin-dashboard.notice>
                            @endif
                            <x-admin-dashboard.sales-proof-card :proof="$order['sales_proof']" compact class="mt-3" />
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada order." />
                    @endforelse
                </div>

                @if ($salesPagination['has_pages'])
                    <nav class="rounded-lg border border-admin-line bg-admin-surface p-3" aria-label="Pagination penjualan">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs font-medium text-admin-muted">
                                Halaman {{ $salesPagination['current_page'] }} dari {{ $salesPagination['last_page'] }}
                                <span>
                                    · {{ $salesPagination['from'] }}-{{ $salesPagination['to'] }} dari {{ $salesPagination['total'] }} order
                                </span>
                            </p>
                            <div class="flex items-center gap-2">
                                @if ($salesPagination['previous_url'])
                                    <x-admin-dashboard.button :href="$salesPagination['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                                @else
                                    <span class="min-h-11 inline-flex items-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Sebelumnya</span>
                                @endif

                                @if ($salesPagination['next_url'])
                                    <x-admin-dashboard.button :href="$salesPagination['next_url']" variant="neutral" size="sm">Berikutnya</x-admin-dashboard.button>
                                @else
                                    <span class="min-h-11 inline-flex items-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Berikutnya</span>
                                @endif
                            </div>
                        </div>
                    </nav>
                @endif
            </div>
