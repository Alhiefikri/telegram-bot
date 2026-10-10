<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Laporan saldo Customer · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
<x-admin-dashboard.linked-shell active-tab="stats" active-navigation-id="balance-reports" title="Laporan saldo Customer">
    <div class="space-y-5">
            <div>
                <p class="text-xs font-medium text-admin-muted">Store Balance · read-only</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink sm:text-3xl">Laporan saldo Customer</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-admin-muted">Saldo IDR dan USDT ditampilkan terpisah. Nilai saldo berasal dari proyeksi akun; rekonsiliasi USDT menampilkan status custody yang tercatat.</p>
            </div>
            <a href="{{ route('admin.dashboard', ['tab' => 'stats']) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke Dashboard</a>

        <section class="grid gap-3 sm:grid-cols-2" aria-label="Total proyeksi per mata uang">
            <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                <p class="text-xs font-medium text-admin-muted">Total proyeksi IDR</p>
                <p class="mt-2 break-words text-2xl font-semibold tracking-tight text-admin-ink">{{ $report['summary']['idr_total'] }}</p>
                <p class="mt-1 text-xs font-medium text-admin-muted">Agregat proyeksi akun, bukan SUM seluruh ledger.</p>
            </x-admin-dashboard.card>
            <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                <p class="text-xs font-medium text-admin-muted">Total proyeksi USDT</p>
                <p class="mt-2 break-words text-2xl font-semibold tracking-tight text-admin-ink">{{ $report['summary']['usdt_total'] }}</p>
                <p class="mt-1 text-xs font-medium text-admin-muted">Agregat proyeksi akun dalam micro-USDT; tanpa konversi FX.</p>
            </x-admin-dashboard.card>
        </section>

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Status treasury USDT">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-admin-ink">Rekonsiliasi treasury USDT terakhir</h2>
                    @if ($report['summary']['treasury'])
                        <p class="mt-1 text-xs font-medium text-admin-muted">{{ $report['summary']['treasury']['checked_at'] }} · verdict {{ $report['summary']['treasury']['verdict'] }}</p>
                    @else
                        <p class="mt-1 text-xs font-medium text-admin-muted">Belum ada run rekonsiliasi penuh yang tercatat.</p>
                    @endif
                </div>
                @if ($report['summary']['treasury'])
                    @php($custody = $report['summary']['treasury'])
                    @if ($custody['custody_state'] === 'available' && $custody['custody_amount'] !== null)
                        <x-admin-dashboard.badge tone="emerald">Custody tersedia · {{ $custody['custody_amount'] }}</x-admin-dashboard.badge>
                    @else
                        <x-admin-dashboard.badge tone="amber">Custody {{ $custody['custody_state'] }} · nilai tidak tersedia</x-admin-dashboard.badge>
                    @endif
                @endif
            </div>
            @if ($report['summary']['treasury'])
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3"><dt class="text-xs font-medium text-admin-muted">Liabilitas</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $custody['liability'] }}</dd></div>
                    <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3"><dt class="text-xs font-medium text-admin-muted">Deposit unresolved</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $custody['unresolved'] }}</dd></div>
                    <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3"><dt class="text-xs font-medium text-admin-muted">Movement masuk / keluar</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $custody['movement_in'] }} / {{ $custody['movement_out'] }}</dd></div>
                    <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3"><dt class="text-xs font-medium text-admin-muted">Finding / aggregate tertunda</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $custody['account_finding_count'] + $custody['receipt_finding_count'] }} / {{ $custody['aggregate_pending_events'] }}</dd></div>
                </dl>
            @endif
        </x-admin-dashboard.card>

        <nav class="flex flex-wrap gap-2" aria-label="Jenis laporan saldo">
            @foreach ([
                'accounts' => 'Akun',
                'entries' => 'Ledger',
                'topups' => 'Top-up',
                'reviews' => 'Review',
                'treasury' => 'Treasury',
                'audit' => 'Audit',
            ] as $sectionKey => $sectionLabel)
                <a href="{{ route('admin.store-balance.reports.index', array_filter(['section' => $sectionKey, 'currency' => $sectionKey === 'treasury' ? 'USDT' : $report['filters']['currency'], 'telegram_id' => $sectionKey === 'treasury' ? null : $report['filters']['telegram_id'], 'limit' => $report['filters']['limit']])) }}"
                   aria-current="{{ $report['filters']['section'] === $sectionKey ? 'page' : 'false' }}"
                   class="filter-pill {{ $report['filters']['section'] === $sectionKey ? 'filter-pill-active' : '' }}">
                    {{ $sectionLabel }}
                </a>
            @endforeach
        </nav>

        @if ($report['filters']['section'] !== 'treasury')
            <nav class="flex flex-wrap gap-2" aria-label="Mata uang laporan">
                @foreach (['IDR', 'USDT'] as $currencyTab)
                    <a href="{{ route('admin.store-balance.reports.index', array_filter(['section' => $report['filters']['section'], 'currency' => $currencyTab, 'telegram_id' => $report['filters']['telegram_id'], 'limit' => $report['filters']['limit']])) }}"
                       aria-current="{{ $report['filters']['currency'] === $currencyTab ? 'page' : 'false' }}"
                       class="filter-pill {{ $report['filters']['currency'] === $currencyTab ? 'filter-pill-active' : '' }}">
                        {{ $currencyTab }}
                    </a>
                @endforeach
            </nav>
        @endif

        <form method="GET" action="{{ route('admin.store-balance.reports.index') }}" class="grid gap-3 rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Filter laporan saldo">
            <input type="hidden" name="section" value="{{ $report['filters']['section'] }}">
            <input type="hidden" name="currency" value="{{ $report['filters']['currency'] }}">
            <div>
                <x-admin-dashboard.field-label for="balance-report-telegram-id">Telegram ID exact</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="balance-report-telegram-id" name="telegram_id" inputmode="numeric" autocomplete="off" value="{{ $report['filters']['telegram_id'] }}" placeholder="Cari berdasarkan ID exact" class="mt-1" :disabled="$report['filters']['section'] === 'treasury'" />
            </div>
            @if ($report['stateOptions'] !== [])
                <div>
                    <x-admin-dashboard.field-label for="balance-report-state">Status / jenis</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="balance-report-state" name="state" class="mt-1">
                        <option value="">Semua</option>
                        @foreach ($report['stateOptions'] as $option)
                            <option value="{{ $option }}" @selected($report['filters']['state'] === $option)>{{ $option }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
            @endif
            <div>
                <x-admin-dashboard.field-label for="balance-report-limit">Baris per halaman</x-admin-dashboard.field-label>
                <x-admin-dashboard.select id="balance-report-limit" name="limit" class="mt-1">
                    @foreach ([25, 50, 100] as $limitOption)
                        <option value="{{ $limitOption }}" @selected($report['filters']['limit'] === $limitOption)>{{ $limitOption }}</option>
                    @endforeach
                </x-admin-dashboard.select>
            </div>
            <div class="flex items-end">
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">Terapkan filter</x-admin-dashboard.button>
            </div>
        </form>

        <section class="space-y-3" aria-label="Hasil laporan saldo">
            @forelse ($report['rows'] as $row)
                <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(10rem,auto)] lg:items-start lg:gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-admin-ink">{{ $row['title'] }}</h2>
                            <x-admin-dashboard.badge>{{ $row['status'] }}</x-admin-dashboard.badge>
                        </div>
                        @if ($row['customer'])
                            <p class="mt-2 text-sm font-medium text-admin-ink">{{ $row['customer'] }}@if ($row['telegram_id']) <span class="text-xs font-medium text-admin-muted">· Telegram {{ $row['telegram_id'] }}</span>@endif</p>
                        @endif
                        <p class="mt-1 break-all font-mono text-xs font-medium text-admin-muted">Referensi: {{ $row['reference'] }}</p>
                        @if ($row['detail'] !== '')
                            <p class="mt-2 break-words text-xs font-medium leading-5 text-admin-muted">{{ $row['detail'] }}</p>
                        @endif
                        <p class="mt-2 text-xs font-medium text-admin-muted">{{ $row['occurred_at'] }}@if ($row['actor']) · oleh {{ $row['actor'] }}@endif</p>
                        @if ($row['proof_url'])
                            <a href="{{ $row['proof_url'] }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline" rel="noreferrer">Unduh bukti pembayaran</a>
                        @endif
                    </div>
                    <p class="mt-2 break-words text-lg font-semibold text-admin-ink lg:mt-0 lg:text-right">{{ $row['amount'] }}</p>
                </x-admin-dashboard.card>
            @empty
                <x-admin-dashboard.empty-state title="Tidak ada data untuk filter ini." />
            @endforelse
        </section>

        @if ($report['nextCursor'])
            <div class="flex justify-end">
                <x-admin-dashboard.button :href="route('admin.store-balance.reports.index', array_filter($report['filters'] + ['cursor' => $report['nextCursor']]))" variant="neutral" size="sm">Laporan lebih lama</x-admin-dashboard.button>
            </div>
        @endif
    </div>
</x-admin-dashboard.linked-shell>
</body>
</html>
