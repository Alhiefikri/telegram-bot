<div id="admin-section-stats" data-admin-return-target tabindex="-1" class="mt-4 space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="admin-overview-title" class="text-2xl font-semibold tracking-tight text-admin-ink">Penjualan hari ini</h2>
            <p class="mt-1 text-sm text-admin-muted">{{ $overview['updated_at'] }} · {{ $overview['timezone'] }} · hari masih berjalan</p>
        </div>
        <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-on:click="copyStoreLink()"><span x-text="copyState === 'Salin' ? 'Salin link toko' : copyState">Salin link toko</span></x-admin-dashboard.button>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr]" aria-label="Penjualan hari ini">
        <div class="rounded-xl border border-admin-line bg-admin-surface p-4 sm:col-span-2 sm:p-5 xl:col-span-1">
            <p class="text-sm font-medium text-admin-muted">Pendapatan hari ini · {{ $overview['currency'] }}</p>
            <p class="mt-2 break-words text-3xl font-semibold tracking-tight text-admin-ink sm:text-4xl">{{ $overview['today_amount'] }}</p>
            <p class="mt-2 text-xs leading-5 text-admin-muted">Pembayaran pesanan lunas setelah biaya provider. Bukan saldo rekening atau laba bersih.</p>
        </div>
        <div class="rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5">
            <p class="text-sm font-medium text-admin-muted">Pesanan lunas hari ini</p>
            <p class="mt-2 text-3xl font-semibold text-admin-ink">{{ $overview['today_orders'] }}</p>
            <p class="mt-2 text-xs text-admin-muted">Seluruh mata uang, tanpa hitung ganda</p>
        </div>
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => 'processing']) }}" class="rounded-xl border border-admin-line bg-admin-surface p-4 transition-colors hover:bg-admin-surface-muted focus-visible:outline-2 focus-visible:outline-admin-focus sm:p-5">
            <p class="text-sm font-medium text-admin-muted">Perlu diproses</p>
            <p class="mt-2 text-3xl font-semibold text-admin-ink">{{ $overview['processing'] }}</p>
            <p class="mt-2 text-xs font-medium text-admin-accent-strong">Lihat antrean pesanan</p>
        </a>
    </section>

    <section class="min-w-0 rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5" aria-labelledby="sales-chart-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="sales-chart-title" class="text-lg font-semibold text-admin-ink">Pendapatan harian</h2>
                <p class="mt-1 text-sm text-admin-muted">{{ $overview['series'][0]['label'] }} sampai {{ $overview['series'][count($overview['series']) - 1]['label'] }} · {{ $overview['currency'] }}</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-admin-ink">{{ $overview['total'] }}</p>
                <p class="text-xs text-admin-muted">Total {{ $overview['days'] }} hari termasuk hari ini</p>
            </div>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap items-end gap-2" aria-label="Filter pendapatan">
                <input type="hidden" name="tab" value="stats">
                <label class="text-xs font-medium text-admin-muted">Periode
                    <x-admin-dashboard.select name="days" class="mt-1">
                        <option value="7" @selected($overview['days'] === 7)>7 hari</option>
                        <option value="30" @selected($overview['days'] === 30)>30 hari</option>
                    </x-admin-dashboard.select>
                </label>
                <label class="text-xs font-medium text-admin-muted">Mata uang
                    <x-admin-dashboard.select name="currency" class="mt-1">
                        <option value="IDR" @selected($overview['currency'] === 'IDR')>IDR</option>
                        <option value="USDT" @selected($overview['currency'] === 'USDT')>USDT</option>
                    </x-admin-dashboard.select>
                </label>
                <x-admin-dashboard.button type="submit" variant="neutral">Tampilkan</x-admin-dashboard.button>
            </form>
        </div>

        @if ($overview['has_sales'])
            <div class="mt-5 flex min-w-0 gap-2 sm:gap-4" aria-label="Grafik pendapatan {{ $overview['currency'] }} selama {{ $overview['days'] }} hari">
                <div class="flex h-52 max-w-24 shrink-0 flex-col justify-between pb-6 text-right text-[0.65rem] text-admin-muted sm:text-xs" aria-hidden="true">
                    @foreach ($overview['axis'] as $tick)
                        <span class="break-words">{{ $tick }}</span>
                    @endforeach
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex h-46 items-end gap-1 border-b border-admin-line sm:gap-2">
                        @foreach ($overview['series'] as $point)
                            <div class="relative flex h-full min-w-0 flex-1 items-end justify-center">
                                <div class="w-full max-w-14 rounded-t-sm bg-admin-accent" style="height: {{ $point['height'] }}%" title="{{ $point['label'] }}: {{ $point['formatted'] }} · {{ $point['orders'] }} pesanan lunas" aria-hidden="true"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex justify-between gap-2 text-[0.65rem] text-admin-muted sm:text-xs" aria-hidden="true">
                        <span>{{ $overview['series'][0]['label'] }}</span>
                        <span>{{ $overview['series'][intdiv(count($overview['series']), 2)]['label'] }}</span>
                        <span>{{ $overview['series'][count($overview['series']) - 1]['label'] }}</span>
                    </div>
                </div>
            </div>
        @else
            <div class="mt-5 rounded-lg bg-admin-surface-muted px-4 py-8 text-center">
                <p class="font-medium text-admin-ink">Belum ada penjualan lunas dalam {{ $overview['days'] }} hari ini.</p>
                <p class="mt-1 text-sm text-admin-muted">Nilai harian tetap nol. Pilih periode atau mata uang lain untuk melihat transaksi sebelumnya.</p>
            </div>
        @endif
        <details class="mt-3">
            <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-medium text-admin-accent-strong focus-visible:outline-2 focus-visible:outline-admin-focus">Lihat nominal per hari</summary>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($overview['series'] as $point)
                    <div class="flex flex-wrap justify-between gap-2 border-b border-admin-line py-2">
                        <dt class="text-admin-muted">{{ $point['label'] }}{{ $point['today'] ? ' (berjalan)' : '' }}</dt>
                        <dd class="text-right font-medium text-admin-ink">{{ $point['formatted'] }}<span class="block text-xs font-normal text-admin-muted">{{ $point['orders'] }} pesanan lunas</span></dd>
                    </div>
                @endforeach
            </dl>
        </details>
        <p class="mt-2 text-xs leading-5 text-admin-muted">Tanggal mengikuti waktu pembayaran lunas. IDR dan USDT tidak digabung; top-up saldo bukan pendapatan penjualan. Refund parsial dan modal supplier tidak dikurangkan pada grafik ini.</p>
    </section>

    @if ($overview['attention'] !== [])
        <section aria-labelledby="admin-overview-attention-title">
            <h2 id="admin-overview-attention-title" class="text-lg font-semibold text-admin-ink">Perlu perhatian</h2>
            <div class="mt-3 divide-y divide-admin-line rounded-xl border border-admin-line bg-admin-surface px-4 sm:px-5">
                @foreach ($overview['attention'] as $attention)
                    <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
                        <div class="min-w-0">
                            <p class="font-semibold text-admin-ink">{{ $attention['title'] }}</p>
                            <p class="mt-1 text-sm leading-6 text-admin-muted">{{ $attention['detail'] }}</p>
                        </div>
                        <x-admin-dashboard.button :href="$attention['url']" variant="neutral" size="sm" class="shrink-0">{{ $attention['action'] }}</x-admin-dashboard.button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section aria-labelledby="recent-orders-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="recent-orders-title" class="text-lg font-semibold text-admin-ink">Pesanan terbaru</h2>
            <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-medium text-admin-accent-strong hover:underline focus-visible:outline-2 focus-visible:outline-admin-focus">Lihat semua pesanan</a>
        </div>
        <div class="mt-2 divide-y divide-admin-line rounded-xl border border-admin-line bg-admin-surface px-4 sm:px-5">
            @forelse ($overview['recent_orders'] as $order)
                <a href="{{ $order['url'] }}" class="grid gap-2 rounded-lg py-4 transition-colors hover:bg-admin-surface-muted focus-visible:outline-2 focus-visible:outline-admin-focus sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0">
                        <p class="break-words text-sm font-semibold text-admin-ink">{{ $order['invoice'] }} · {{ $order['customer'] }}</p>
                        <p class="mt-1 text-xs text-admin-muted">{{ $order['created_at'] }} · Pembayaran: {{ $order['payment_status'] }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3 sm:justify-end">
                        <p class="text-sm font-semibold text-admin-ink">{{ $order['amount'] }}</p>
                        <x-admin-dashboard.badge :tone="$order['tone']">{{ $order['status'] }}</x-admin-dashboard.badge>
                    </div>
                </a>
            @empty
                <p class="py-6 text-sm text-admin-muted">Belum ada pesanan. Bagikan link toko agar pelanggan bisa mulai berbelanja.</p>
            @endforelse
        </div>
    </section>
</div>
