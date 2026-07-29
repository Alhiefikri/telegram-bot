            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PENJUALAN TAB (Pesanan)                            ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-sales" data-admin-return-target tabindex="-1" x-cloak x-show="activeTab === 'penjualan'" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">🛒 Penjualan Saya</h2>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Order masuk dan diproses</p>
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
                <div class="space-y-3">
                    @forelse ($orders as $order)
                        <x-admin-dashboard.card
                            id="sales-proof-{{ $order['id'] }}"
                            data-admin-return-target
                            tabindex="-1"
                            as="article"
                            padding="compact"
                            shadow="sm"
                            class="rounded-[1.125rem]"
                            data-order-status="{{ $order['order_status'] }}"
                            data-order-filter-groups="{{ implode(' ', $order['filter_groups']) }}"
                        >
                            {{-- Invoice + status --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[10px] font-bold uppercase tracking-wider text-brand-muted">{{ $order['invoice_number'] }}</p>
                                    <p class="mt-1 truncate font-display text-base font-bold text-neo-ink">{{ $order['product_name'] }}</p>
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
                            <div class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3">
                                <div class="min-w-0">
                                    <p class="text-[9px] font-bold uppercase text-brand-muted">Pembeli</p>
                                    <p class="mt-0.5 truncate text-sm font-bold text-neo-ink">{{ $order['customer'] }}</p>
                                </div>
                                <div class="min-w-0 text-right">
                                    <p class="text-[9px] font-bold uppercase text-brand-muted">Tanggal</p>
                                    <p class="mt-0.5 text-sm font-bold text-neo-ink">{{ $order['created_at_label'] }}</p>
                                </div>
                            </div>

                            {{-- Payment badge + item count --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <x-admin-dashboard.badge :tone="$order['payment_status_tone']">
                                    {{ $order['payment_status_label'] }}
                                </x-admin-dashboard.badge>
                                <span class="text-xs font-bold text-brand-muted">{{ $order['item_count'] }} item</span>
                                <a href="{{ $order['detail_url'] }}" class="text-xs font-bold text-neo-ink underline">Detail order</a>
                            </div>
                            @if ($order['payment_attention'])
                                <x-admin-dashboard.notice :tone="$order['payment_attention']['tone']" class="mt-3 rounded-xl text-xs">
                                    <span class="font-black uppercase">{{ $order['payment_attention']['label'] }}:</span> {{ $order['payment_attention']['message'] }}
                                </x-admin-dashboard.notice>
                            @endif
                            <x-admin-dashboard.sales-proof-card :proof="$order['sales_proof']" compact class="mt-3" />
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada order." />
                    @endforelse
                </div>

                @if ($salesPagination['has_pages'])
                    <nav class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm" aria-label="Pagination penjualan">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs font-black uppercase tracking-wide text-brand-muted">
                                Halaman {{ $salesPagination['current_page'] }} dari {{ $salesPagination['last_page'] }}
                                <span class="normal-case tracking-normal">
                                    · {{ $salesPagination['from'] }}-{{ $salesPagination['to'] }} dari {{ $salesPagination['total'] }} order
                                </span>
                            </p>
                            <div class="flex items-center gap-2">
                                @if ($salesPagination['previous_url'])
                                    <x-admin-dashboard.button :href="$salesPagination['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                                @else
                                    <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Sebelumnya</span>
                                @endif

                                @if ($salesPagination['next_url'])
                                    <x-admin-dashboard.button :href="$salesPagination['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                                @else
                                    <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Berikutnya</span>
                                @endif
                            </div>
                        </div>
                    </nav>
                @endif
            </div>
