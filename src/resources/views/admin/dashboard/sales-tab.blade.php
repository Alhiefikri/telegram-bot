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
                                <span class="neo-badge" style="background: {{ $order['order_status_badge']['background'] }}; color: {{ $order['order_status_badge']['color'] }};">
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
                                <span class="neo-badge" style="background: {{ $order['payment_status_badge']['background'] }}; color: {{ $order['payment_status_badge']['color'] }};">
                                    {{ $order['payment_status_label'] }}
                                </span>
                                <span class="text-xs font-bold" style="color: #64748b;">{{ $order['item_count'] }} item</span>
                                <a href="{{ $order['detail_url'] }}" class="text-xs font-bold underline" style="color: #050505;">Detail order</a>
                            </div>
                            {{-- Sales Proof status + retry --}}
                            @php($proof = $order['sales_proof'])
                            <div class="mt-3 rounded-lg p-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Sales Proof Post</p>
                                        <p class="mt-1 text-xs font-bold" style="color: #050505;">{{ $proof['label'] }}</p>
                                    </div>
                                    <span class="neo-badge" style="background: {{ $proof['badge']['background'] }}; color: {{ $proof['badge']['color'] }};">
                                        {{ $proof['status'] }}
                                    </span>
                                </div>

                                @if ($proof['message_id'])
                                    <p class="mt-2 break-words text-xs font-semibold" style="color: #334155;">Telegram message ID: {{ $proof['message_id'] }}</p>
                                @endif

                                @if ($proof['last_error'])
                                    <p class="mt-2 break-words text-xs font-semibold" style="color: #dc2626;">Error: {{ $proof['last_error'] }}</p>
                                @endif

                                @if ($proof['status'] !== 'none')
                                    <p class="mt-2 text-[10px] font-bold uppercase" style="color: #64748b;">Attempts: {{ $proof['attempts'] }}</p>
                                @endif

                                @if ($proof['can_retry'])
                                    <form method="POST" action="{{ $proof['retry_url'] }}" class="mt-3">
                                        @csrf
                                        <button type="submit" class="neo-btn neo-btn-secondary w-full justify-center text-xs">Retry Sales Proof Post</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl p-6 text-center" style="background: #fff; border: 2px dashed #050505;">
                            <p class="font-display text-sm font-bold" style="color: #64748b;">Belum ada order.</p>
                        </div>
                    @endforelse
                </div>
            </div>
