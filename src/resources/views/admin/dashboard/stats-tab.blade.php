@php
    $period = $overview['period'];
    $months = $overview['months'];
    $filter = $overview['filter'];
    $periodOptions = $overview['period_options'];
    $chartPoints = $period['points'];
    $monthTileTone = ['up' => 'emerald', 'down' => 'rose', 'flat' => 'slate'];
@endphp
<div id="admin-section-stats" data-admin-return-target tabindex="-1" class="mt-4 space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="admin-overview-title" class="text-2xl font-semibold tracking-tight text-admin-ink">Ringkasan penjualan</h2>
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

    <section class="grid gap-3 sm:grid-cols-2 {{ $months['selected'] !== null ? 'xl:grid-cols-3' : 'xl:grid-cols-2' }}" aria-label="Pendapatan bulanan {{ $overview['currency'] }}">
        <article class="rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="text-sm font-medium text-admin-muted">Bulan ini · {{ $months['current']['label'] }}</p>
                @if ($months['current']['delta'] !== null)
                    <x-admin-dashboard.badge :tone="$monthTileTone[$months['current']['delta']['tone']] ?? 'slate'">{{ $months['current']['delta']['label'] }}</x-admin-dashboard.badge>
                @endif
            </div>
            <p class="mt-2 break-words text-2xl font-semibold tracking-tight text-admin-ink">{{ $months['current']['total'] }}</p>
            <p class="mt-2 text-xs leading-5 text-admin-muted">
                {{ $months['current']['orders'] }} pesanan lunas
                @if ($months['current']['delta'] !== null)
                    · {{ $months['current']['delta']['compare_label'] }}
                @endif
            </p>
        </article>
        <article class="rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5">
            <p class="text-sm font-medium text-admin-muted">Bulan kemarin · {{ $months['previous']['label'] }}</p>
            <p class="mt-2 break-words text-2xl font-semibold tracking-tight text-admin-ink">{{ $months['previous']['total'] }}</p>
            <p class="mt-2 text-xs leading-5 text-admin-muted">{{ $months['previous']['orders'] }} pesanan lunas · bulan penuh</p>
        </article>
        @if ($months['selected'] !== null)
            <article class="rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5" data-admin-target-month>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="text-sm font-medium text-admin-muted">Bulan dituju · {{ $months['selected']['label'] }}</p>
                    @if ($months['selected']['delta'] !== null)
                        <x-admin-dashboard.badge :tone="$monthTileTone[$months['selected']['delta']['tone']] ?? 'slate'">{{ $months['selected']['delta']['label'] }}</x-admin-dashboard.badge>
                    @endif
                </div>
                <p class="mt-2 break-words text-2xl font-semibold tracking-tight text-admin-ink">{{ $months['selected']['total'] }}</p>
                <p class="mt-2 text-xs leading-5 text-admin-muted">
                    {{ $months['selected']['orders'] }} pesanan lunas
                    @if ($months['selected']['delta'] !== null)
                        · {{ $months['selected']['delta']['compare_label'] }}
                    @endif
                </p>
            </article>
        @endif
    </section>

    <section class="min-w-0 rounded-xl border border-admin-line bg-admin-surface p-4 sm:p-5" aria-labelledby="sales-chart-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 id="sales-chart-title" class="text-lg font-semibold text-admin-ink">Pendapatan {{ $period['granularity'] === 'day' ? 'harian' : ($period['granularity'] === 'week' ? 'mingguan' : 'bulanan') }} · {{ $overview['currency'] }}</h2>
                <p class="mt-1 text-sm text-admin-muted">{{ $period['window_label'] }} · {{ $period['range_label'] }}</p>
                @if ($period['partial_label'] !== null)
                    <p class="mt-1 text-xs leading-5 text-admin-muted">{{ $period['partial_label'] }}; nilainya bisa bertambah.</p>
                @endif
            </div>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap items-end gap-2" aria-label="Filter pendapatan">
                <input type="hidden" name="tab" value="stats">
                <input type="hidden" name="period" value="{{ $filter['mode'] }}">
                @if ($filter['mode'] === \App\Services\Admin\AdminDashboardOverviewData::PERIOD_DAILY)
                    <label class="text-xs font-medium text-admin-muted">Periode
                        <x-admin-dashboard.select name="days" class="mt-1">
                            <option value="7" @selected($filter['days'] === 7)>7 hari</option>
                            <option value="30" @selected($filter['days'] === 30)>30 hari</option>
                        </x-admin-dashboard.select>
                    </label>
                @elseif ($filter['mode'] === \App\Services\Admin\AdminDashboardOverviewData::PERIOD_MONTH)
                    <label class="text-xs font-medium text-admin-muted">Bulan
                        <x-admin-dashboard.input type="month" name="month" :value="$filter['month']" :min="$months['bounds']['min']" :max="$months['bounds']['max']" class="mt-1" required />
                    </label>
                @endif
                <label class="text-xs font-medium text-admin-muted">Mata uang
                    <x-admin-dashboard.select name="currency" class="mt-1">
                        <option value="IDR" @selected($overview['currency'] === 'IDR')>IDR</option>
                        <option value="USDT" @selected($overview['currency'] === 'USDT')>USDT</option>
                    </x-admin-dashboard.select>
                </label>
                <x-admin-dashboard.button type="submit" variant="neutral">Tampilkan</x-admin-dashboard.button>
            </form>
        </div>

        <div class="mt-4 flex flex-wrap gap-2" aria-label="Rentang pendapatan">
            @foreach ($periodOptions as $option)
                <a
                    href="{{ $option['url'] }}"
                    data-period-option="{{ $option['key'] }}"
                    @if ($option['active']) aria-current="page" @endif
                    title="{{ $option['hint'] }}"
                    @class([
                        'inline-flex min-h-11 items-center rounded-lg border px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-admin-focus',
                        'border-admin-accent bg-admin-accent-soft text-admin-accent-strong' => $option['active'],
                        'border-admin-line bg-admin-surface text-admin-ink hover:bg-admin-surface-muted' => ! $option['active'],
                    ])
                >{{ $option['label'] }}</a>
            @endforeach
        </div>

        @if ($period['has_sales'])
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <x-admin-dashboard.mini-stat label="Total {{ $period['window_label'] }}" align="left">
                    <span class="break-words text-base font-semibold text-admin-ink">{{ $period['total'] }}</span>
                    <span class="block text-xs font-normal text-admin-muted">{{ $period['orders'] }} pesanan lunas</span>
                </x-admin-dashboard.mini-stat>
                <x-admin-dashboard.mini-stat label="Rata-rata per {{ $period['bucket_label'] }}" align="left">
                    <span class="break-words text-base font-semibold text-admin-ink">{{ $period['average'] }}</span>
                    <span class="block text-xs font-normal text-admin-muted">dari {{ $period['buckets'] }} {{ $period['bucket_label'] }}</span>
                </x-admin-dashboard.mini-stat>
                @if ($period['peak'] !== null)
                    <x-admin-dashboard.mini-stat label="Nilai tertinggi" align="left">
                        <span class="break-words text-base font-semibold text-admin-ink">{{ $period['peak']['formatted'] }}</span>
                        <span class="block text-xs font-normal text-admin-muted">{{ $period['peak']['label'] }} · {{ $period['peak']['orders'] }} pesanan lunas</span>
                    </x-admin-dashboard.mini-stat>
                @endif
            </div>

            <div
                x-data="salesChart(@js($chartPoints))"
                x-on:mouseleave="hide()"
                class="mt-5 flex min-w-0 gap-2 sm:gap-4"
                aria-label="Grafik pendapatan {{ $overview['currency'] }} {{ $period['window_label'] }}"
            >
                <div class="flex h-52 w-16 shrink-0 flex-col justify-between pb-6 text-right text-[0.6rem] text-admin-muted sm:w-20 sm:text-xs lg:max-w-24" aria-hidden="true">
                    @foreach ($period['axis'] as $tick)
                        <span class="break-words">{{ $tick }}</span>
                    @endforeach
                </div>
                <div class="min-w-0 flex-1">
                    <div class="relative h-52 border-b border-admin-line" x-ref="plot">
                        <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-admin-line" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute inset-x-0 border-t border-dashed border-admin-line" style="bottom: {{ max(4, min(100, $period['average_height'])) }}%" aria-hidden="true">
                            <span class="absolute -top-5 right-0 rounded bg-admin-surface px-1 text-[0.6rem] font-medium text-admin-muted">rata-rata {{ $period['average'] }}</span>
                        </div>
                        <div class="flex h-full items-end gap-[3px] pb-1 sm:gap-2">
                            @foreach ($chartPoints as $index => $point)
                                <div
                                    tabindex="0"
                                    role="img"
                                    data-chart-bar="{{ $point['key'] }}"
                                    aria-label="{{ $point['range_label'] }}: {{ $point['formatted'] }}, {{ $point['orders'] }} pesanan lunas"
                                    x-on:mouseenter="show({{ $index }})"
                                    x-on:focus="show({{ $index }})"
                                    x-on:blur="hide()"
                                    x-bind:class="activeIndex === {{ $index }} ? 'bg-admin-accent-soft/60' : ''"
                                    class="flex h-full min-w-0 flex-1 cursor-pointer items-end justify-center rounded-t-md transition-colors focus-visible:outline-2 focus-visible:outline-admin-focus"
                                >
                                    <div
                                        aria-hidden="true"
                                        class="w-full max-w-14 rounded-t-sm {{ $point['is_peak'] ? 'bg-admin-accent-strong' : 'bg-admin-accent' }} {{ $point['is_current'] ? 'ring-1 ring-inset ring-admin-accent-ink/40' : '' }}"
                                        style="height: {{ $point['height'] }}%"
                                    ></div>
                                </div>
                            @endforeach
                        </div>
                        <div
                            x-cloak
                            x-show="activePoint()"
                            x-bind:style="'left: ' + tooltipLeft"
                            data-chart-tooltip
                            x-ref="tooltip"
                            class="pointer-events-none absolute bottom-2 z-10 w-44 -translate-x-1/2 rounded-lg border border-admin-line bg-admin-surface px-2.5 py-2 text-left shadow-admin-lg sm:w-52 sm:px-3"
                        >
                            <p class="text-xs font-semibold text-admin-ink" x-text="activePoint()?.range_label"></p>
                            <p class="mt-1 text-sm font-semibold text-admin-ink" x-text="activePoint()?.formatted"></p>
                            <p class="mt-0.5 text-[0.7rem] text-admin-muted sm:text-xs">
                                <span x-text="activePoint()?.orders"></span> pesanan lunas · <span x-text="activePoint()?.share"></span>% dari total
                            </p>
                        </div>
                    </div>
                    <div class="mt-2 flex justify-between gap-2 text-[0.65rem] text-admin-muted sm:text-xs" aria-hidden="true">
                        <span>{{ $chartPoints[0]['label'] }}</span>
                        <span>{{ $chartPoints[intdiv(count($chartPoints), 2)]['label'] }}</span>
                        <span>{{ $chartPoints[count($chartPoints) - 1]['label'] }}</span>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-admin-muted">Arahkan kursor atau gunakan Tab pada batang untuk melihat nominal per {{ $period['bucket_label'] }}. Garis putus-putus adalah rata-rata periode.</p>
                </div>
            </div>
        @else
            <div class="mt-5 rounded-lg bg-admin-surface-muted px-4 py-8 text-center">
                <p class="font-medium text-admin-ink">Belum ada penjualan lunas pada {{ $period['window_label'] }}.</p>
                <p class="mt-1 text-sm text-admin-muted">Nilai per {{ $period['bucket_label'] }} tetap nol. Pilih periode, bulan, atau mata uang lain untuk melihat transaksi sebelumnya.</p>
            </div>
        @endif

        <details class="mt-3">
            <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-medium text-admin-accent-strong focus-visible:outline-2 focus-visible:outline-admin-focus">Lihat nominal per {{ $period['bucket_label'] }}</summary>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($chartPoints as $point)
                    <div class="flex flex-wrap justify-between gap-2 border-b border-admin-line py-2">
                        <dt class="text-admin-muted">{{ $point['range_label'] }}{{ $point['is_current'] ? ' (berjalan)' : '' }}</dt>
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
