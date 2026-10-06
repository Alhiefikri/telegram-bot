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
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
    <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-neo-xl border-[3px] border-neo-ink bg-white px-3 text-xs font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    <main class="mx-auto w-full max-w-7xl space-y-5 p-4 pt-20 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-black uppercase tracking-[.18em] text-brand-muted">Store Balance · read-only</p>
                <h1 class="mt-1 font-display text-2xl font-black text-neo-ink sm:text-3xl">Laporan saldo Customer</h1>
                <p class="mt-2 max-w-3xl text-sm font-semibold leading-6 text-brand-muted">Saldo IDR dan USDT ditampilkan terpisah. Nilai saldo berasal dari proyeksi akun; rekonsiliasi USDT menampilkan status custody yang tercatat.</p>
            </div>
            <a href="{{ route('admin.dashboard', ['tab' => 'stats']) }}" class="inline-flex min-h-11 items-center rounded-xl border-2 border-neo-ink bg-white px-4 py-2 text-sm font-black text-neo-ink">Kembali ke Dashboard</a>
        </header>

        <section class="grid gap-3 sm:grid-cols-2" aria-label="Total proyeksi per mata uang">
            <article class="rounded-xl border-2 border-neo-ink bg-white p-4 shadow-neo-sm">
                <p class="text-xs font-black uppercase tracking-wider text-brand-muted">Total proyeksi IDR</p>
                <p class="mt-2 break-words font-display text-2xl font-black text-neo-ink">{{ $report['summary']['idr_total'] }}</p>
                <p class="mt-1 text-xs font-semibold text-brand-muted">Agregat proyeksi akun, bukan SUM seluruh ledger.</p>
            </article>
            <article class="rounded-xl border-2 border-neo-ink bg-white p-4 shadow-neo-sm">
                <p class="text-xs font-black uppercase tracking-wider text-brand-muted">Total proyeksi USDT</p>
                <p class="mt-2 break-words font-display text-2xl font-black text-neo-ink">{{ $report['summary']['usdt_total'] }}</p>
                <p class="mt-1 text-xs font-semibold text-brand-muted">Agregat proyeksi akun dalam micro-USDT; tanpa konversi FX.</p>
            </article>
        </section>

        <section class="rounded-xl border-2 border-neo-ink bg-white p-4 shadow-neo-sm" aria-label="Status treasury USDT">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-display text-lg font-black text-neo-ink">Rekonsiliasi treasury USDT terakhir</h2>
                    @if ($report['summary']['treasury'])
                        <p class="mt-1 text-xs font-semibold text-brand-muted">{{ $report['summary']['treasury']['checked_at'] }} · verdict {{ $report['summary']['treasury']['verdict'] }}</p>
                    @else
                        <p class="mt-1 text-xs font-semibold text-brand-muted">Belum ada run rekonsiliasi penuh yang tercatat.</p>
                    @endif
                </div>
                @if ($report['summary']['treasury'])
                    @php($custody = $report['summary']['treasury'])
                    @if ($custody['custody_state'] === 'available' && $custody['custody_amount'] !== null)
                        <span class="rounded-full border-2 border-neo-ink bg-emerald-100 px-3 py-1 text-xs font-black">Custody tersedia · {{ $custody['custody_amount'] }}</span>
                    @else
                        <span class="rounded-full border-2 border-neo-ink bg-amber-100 px-3 py-1 text-xs font-black">Custody {{ $custody['custody_state'] }} · nilai tidak tersedia</span>
                    @endif
                @endif
            </div>
            @if ($report['summary']['treasury'])
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-neo-ink/20 p-3"><dt class="text-xs font-bold text-brand-muted">Liabilitas</dt><dd class="mt-1 font-black">{{ $custody['liability'] }}</dd></div>
                    <div class="rounded-lg border border-neo-ink/20 p-3"><dt class="text-xs font-bold text-brand-muted">Deposit unresolved</dt><dd class="mt-1 font-black">{{ $custody['unresolved'] }}</dd></div>
                    <div class="rounded-lg border border-neo-ink/20 p-3"><dt class="text-xs font-bold text-brand-muted">Movement masuk / keluar</dt><dd class="mt-1 font-black">{{ $custody['movement_in'] }} / {{ $custody['movement_out'] }}</dd></div>
                    <div class="rounded-lg border border-neo-ink/20 p-3"><dt class="text-xs font-bold text-brand-muted">Finding / aggregate tertunda</dt><dd class="mt-1 font-black">{{ $custody['account_finding_count'] + $custody['receipt_finding_count'] }} / {{ $custody['aggregate_pending_events'] }}</dd></div>
                </dl>
            @endif
        </section>

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
                   class="inline-flex min-h-10 items-center rounded-full border-2 border-neo-ink px-4 py-2 text-xs font-black {{ $report['filters']['section'] === $sectionKey ? 'bg-brand-yellow text-neo-ink' : 'bg-white text-neo-ink' }}">
                    {{ $sectionLabel }}
                </a>
            @endforeach
        </nav>

        @if ($report['filters']['section'] !== 'treasury')
            <nav class="flex flex-wrap gap-2" aria-label="Mata uang laporan">
                @foreach (['IDR', 'USDT'] as $currencyTab)
                    <a href="{{ route('admin.store-balance.reports.index', array_filter(['section' => $report['filters']['section'], 'currency' => $currencyTab, 'telegram_id' => $report['filters']['telegram_id'], 'limit' => $report['filters']['limit']])) }}"
                       aria-current="{{ $report['filters']['currency'] === $currencyTab ? 'page' : 'false' }}"
                       class="inline-flex min-h-10 items-center rounded-full border-2 border-neo-ink px-4 py-2 text-xs font-black {{ $report['filters']['currency'] === $currencyTab ? 'bg-brand-yellow text-neo-ink' : 'bg-white text-neo-ink' }}">
                        {{ $currencyTab }}
                    </a>
                @endforeach
            </nav>
        @endif

        <form method="GET" action="{{ route('admin.store-balance.reports.index') }}" class="grid gap-3 rounded-xl border-2 border-neo-ink bg-white p-4 shadow-neo-sm sm:grid-cols-2 lg:grid-cols-4" aria-label="Filter laporan saldo">
            <input type="hidden" name="section" value="{{ $report['filters']['section'] }}">
            <input type="hidden" name="currency" value="{{ $report['filters']['currency'] }}">
            <label class="text-xs font-black text-neo-ink">
                Telegram ID exact
                <input name="telegram_id" inputmode="numeric" autocomplete="off" value="{{ $report['filters']['telegram_id'] }}" placeholder="Cari berdasarkan ID exact" class="neo-input mt-1 min-h-11 w-full bg-white px-3 text-sm font-semibold" @if ($report['filters']['section'] === 'treasury') disabled @endif>
            </label>
            @if ($report['stateOptions'] !== [])
                <label class="text-xs font-black text-neo-ink">
                    Status / jenis
                    <select name="state" class="neo-input mt-1 min-h-11 w-full bg-white px-3 text-sm font-semibold">
                        <option value="">Semua</option>
                        @foreach ($report['stateOptions'] as $option)
                            <option value="{{ $option }}" @selected($report['filters']['state'] === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="text-xs font-black text-neo-ink">
                Baris per halaman
                <select name="limit" class="neo-input mt-1 min-h-11 w-full bg-white px-3 text-sm font-semibold">
                    @foreach ([25, 50, 100] as $limitOption)
                        <option value="{{ $limitOption }}" @selected($report['filters']['limit'] === $limitOption)>{{ $limitOption }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <button type="submit" class="min-h-11 w-full rounded-xl border-2 border-neo-ink bg-brand-yellow px-4 py-2 text-sm font-black text-neo-ink">Terapkan filter</button>
            </div>
        </form>

        <section class="space-y-3" aria-label="Hasil laporan saldo">
            @forelse ($report['rows'] as $row)
                <article class="grid gap-3 rounded-xl border-2 border-neo-ink bg-white p-4 shadow-neo-sm lg:grid-cols-[minmax(0,1fr)_minmax(10rem,auto)] lg:items-start">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-base font-black text-neo-ink">{{ $row['title'] }}</h2>
                            <span class="rounded-full border border-neo-ink/30 bg-slate-100 px-2 py-1 text-[10px] font-black">{{ $row['status'] }}</span>
                        </div>
                        @if ($row['customer'])
                            <p class="mt-2 text-sm font-bold">{{ $row['customer'] }}@if ($row['telegram_id']) <span class="text-xs text-brand-muted">· Telegram {{ $row['telegram_id'] }}</span>@endif</p>
                        @endif
                        <p class="mt-1 break-all text-xs font-semibold text-brand-muted">Referensi: {{ $row['reference'] }}</p>
                        @if ($row['detail'] !== '')
                            <p class="mt-2 break-words text-xs font-semibold leading-5 text-brand-muted">{{ $row['detail'] }}</p>
                        @endif
                        <p class="mt-2 text-xs font-semibold text-brand-muted">{{ $row['occurred_at'] }}@if ($row['actor']) · oleh {{ $row['actor'] }}@endif</p>
                        @if ($row['proof_url'])
                            <a href="{{ $row['proof_url'] }}" class="mt-3 inline-flex min-h-10 items-center rounded-lg border-2 border-neo-ink bg-brand-yellow px-3 py-2 text-xs font-black" rel="noreferrer">Unduh bukti pembayaran</a>
                        @endif
                    </div>
                    <p class="break-words font-display text-lg font-black text-neo-ink lg:text-right">{{ $row['amount'] }}</p>
                </article>
            @empty
                <div class="rounded-xl border-2 border-neo-ink bg-white p-8 text-center text-sm font-bold text-brand-muted">Tidak ada data untuk filter ini.</div>
            @endforelse
        </section>

        @if ($report['nextCursor'])
            <div class="flex justify-end">
                <a href="{{ route('admin.store-balance.reports.index', array_filter($report['filters'] + ['cursor' => $report['nextCursor']])) }}" class="min-h-11 rounded-xl border-2 border-neo-ink bg-brand-yellow px-4 py-3 text-sm font-black text-neo-ink">Laporan lebih lama</a>
            </div>
        @endif
    </main>
</body>
</html>
