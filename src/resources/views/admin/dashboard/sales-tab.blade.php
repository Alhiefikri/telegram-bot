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

                {{-- Invoice search --}}
                <section class="rounded-xl border border-admin-line bg-admin-surface p-3 sm:p-4" aria-labelledby="sales-invoice-search-title">
                    <h3 id="sales-invoice-search-title" class="text-sm font-semibold text-admin-ink">Cari pesanan lewat invoice</h3>
                    <p class="mt-1 text-xs leading-5 text-admin-muted">Tempel nomor invoice (mis. INV-20261010-…). Hasilnya menampilkan status pengiriman kredensial ke pelanggan.</p>
                    <form method="GET" action="{{ route('admin.dashboard') }}" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                        <input type="hidden" name="tab" value="penjualan">
                        @if ($salesActiveFilter !== 'all')
                            <input type="hidden" name="sales_filter" value="{{ $salesActiveFilter }}">
                        @endif
                        <div class="min-w-0 flex-1">
                            <x-admin-dashboard.field-label for="sales-invoice-search">Nomor invoice</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input
                                id="sales-invoice-search"
                                name="invoice"
                                :value="$salesInvoice"
                                maxlength="80"
                                autocomplete="off"
                                placeholder="INV-20261010-01JX8Y2M9"
                                class="mt-1.5"
                                aria-describedby="sales-invoice-search-hint"
                            />
                        </div>
                        <div class="flex gap-2">
                            <x-admin-dashboard.button type="submit" variant="primary" class="flex-1 sm:flex-none">Cari invoice</x-admin-dashboard.button>
                            @if ($salesInvoice !== '')
                                <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => $salesActiveFilter])" variant="neutral">Reset</x-admin-dashboard.button>
                            @endif
                        </div>
                    </form>
                    <p id="sales-invoice-search-hint" class="mt-2 text-xs leading-5 text-admin-muted">
                        @if ($salesInvoiceTooShort)
                            Minimal 3 karakter. Pencarian diabaikan dan seluruh pesanan ditampilkan.
                        @elseif ($salesInvoice !== '')
                            Menampilkan {{ $salesPagination['total'] ?? 0 }} pesanan yang cocok dengan {{ $salesInvoice }}.
                        @else
                            Pencarian tidak aktif. Semua pesanan ditampilkan sesuai filter status di bawah.
                        @endif
                    </p>
                </section>

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
                            @if ($order['credential_delivery'] !== null)
                                <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3" data-credential-delivery>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs font-medium text-admin-muted">Pengiriman kredensial</p>
                                        <x-admin-dashboard.badge :tone="$order['credential_delivery']['tone']">{{ $order['credential_delivery']['verdict'] }}</x-admin-dashboard.badge>
                                    </div>
                                    <ul class="mt-2 space-y-1 text-xs leading-5 text-admin-muted">
                                        <li>{{ $order['credential_delivery']['telegram'] }}</li>
                                        @if ($order['credential_delivery']['web'] !== null)
                                            <li>{{ $order['credential_delivery']['web'] }}</li>
                                        @endif
                                        @if ($order['credential_delivery']['activity'] !== null)
                                            <li>{{ $order['credential_delivery']['activity'] }}</li>
                                        @endif
                                    </ul>
                                    @if ($order['credential_delivery']['review_url'] !== null)
                                        <a href="{{ $order['credential_delivery']['review_url'] }}" class="mt-1 inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Buka Manual Delivery Review</a>
                                    @endif
                                </div>
                            @endif
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
