<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Tambah Saldo Customer · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
    <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-neo-xl border-[3px] border-neo-ink bg-white px-3 text-xs font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    <main class="mx-auto max-w-6xl space-y-4 p-4 pt-20 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'stats']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke Ringkasan Admin</a>

        @if (session('status'))
            <x-admin-dashboard.notice tone="success" role="status" aria-live="polite">{{ session('status') }}</x-admin-dashboard.notice>
        @endif
        @if ($errors->any())
            <x-admin-dashboard.notice tone="danger" role="alert" aria-live="assertive">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-admin-dashboard.notice>
        @endif

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-2xl" aria-label="Tambah saldo Rupiah">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Store Balance · IDR</p>
            <h1 class="mt-1 text-2xl font-black">Tambah saldo Customer</h1>
            <p class="mt-2 max-w-3xl text-sm font-semibold leading-6 text-brand-muted">
                Cari Customer memakai Telegram ID numerik exact. Periksa identitas dan nominal pada preview, lalu konfirmasi dengan password Owner. Form ini menambahkan nominal ke saldo; form ini tidak menetapkan saldo akhir.
            </p>

            <form method="GET" action="{{ route('admin.store-balance.adjustments.index') }}" class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" aria-label="Cari Customer berdasarkan Telegram ID">
                <label class="block text-xs font-bold text-neo-ink" for="balance-adjustment-telegram-id">
                    Telegram ID
                    <input
                        id="balance-adjustment-telegram-id"
                        name="telegram_id"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        autocomplete="off"
                        value="{{ old('telegram_id', $telegramId) }}"
                        class="neo-input mt-1 w-full bg-white px-3 py-2.5 text-base font-semibold"
                        aria-describedby="balance-adjustment-id-help"
                        required
                    >
                </label>
                <p id="balance-adjustment-id-help" class="text-[11px] font-semibold leading-5 text-brand-muted sm:col-span-2 sm:row-start-2">ID dicocokkan persis dengan Customer yang sudah terdaftar. Username hanya informasi tampilan.</p>
                <x-admin-dashboard.button type="submit" variant="ink" size="sm" class="w-full sm:col-start-2 sm:row-start-1 sm:w-auto">Cari Customer</x-admin-dashboard.button>
            </form>

            @if ($lookupError)
                <x-admin-dashboard.notice tone="warning" class="mt-4" role="status">{{ $lookupError }}</x-admin-dashboard.notice>
            @endif

            @if ($customer)
                @php($customerId = (string) $customer->telegram_id)
                <section class="mt-5 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-4" aria-label="Customer ditemukan">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Customer</p>
                            <p class="mt-1 break-words text-base font-black">{{ $customer->first_name ?: 'Customer Telegram' }}</p>
                            <p class="mt-1 text-sm font-semibold text-brand-muted">{{ $customer->username ? '@'.$customer->username : 'Username belum tersedia' }}</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Telegram ID · tidak dapat diubah</p>
                            <p class="mt-1 break-all font-mono text-base font-black" data-customer-telegram-id="{{ $customerId }}">{{ $customerId }}</p>
                            @if ($customerBalance?->isAvailable())
                                <p class="mt-1 text-sm font-semibold text-brand-muted">Saldo Rupiah saat ini <span class="font-black text-neo-ink">Rp {{ number_format($customerBalance->balance(), 0, ',', '.') }}</span></p>
                            @else
                                <p class="mt-1 text-sm font-black text-brand-slate-dark">Saldo Rupiah tidak dapat ditampilkan sampai ledger direkonsiliasi.</p>
                            @endif
                        </div>
                    </div>

                    @if ($customerBalance?->isAvailable())
                    <form
                        method="POST"
                        action="{{ route('admin.store-balance.adjustments.previews.store') }}"
                        class="mt-4 grid gap-3 border-t border-neo-ink/10 pt-4"
                        aria-label="Isi penambahan saldo"
                        x-data="{ submitting: false }"
                        x-on:submit.prevent="if (!submitting) { submitting = true; $nextTick(() => $el.submit()); }"
                    >
                        @csrf
                        <input type="hidden" name="telegram_id" value="{{ $customerId }}">
                        <label class="block text-xs font-bold text-neo-ink" for="balance-adjustment-amount">
                            Jumlah tambahan Rupiah
                            <input
                                id="balance-adjustment-amount"
                                name="amount"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                autocomplete="off"
                                value="{{ old('amount') }}"
                                class="neo-input mt-1 w-full bg-white px-3 py-2.5 text-base font-semibold"
                                required
                            >
                        </label>
                        <p class="-mt-2 text-[11px] font-semibold leading-5 text-brand-muted">Masukkan angka Rupiah positif tanpa titik, koma, atau desimal.</p>
                        <label class="block text-xs font-bold text-neo-ink" for="balance-adjustment-reason">
                            Alasan penambahan
                            <textarea id="balance-adjustment-reason" name="reason" rows="3" maxlength="500" class="neo-input mt-1 w-full bg-white px-3 py-2.5 text-sm font-semibold" required>{{ old('reason') }}</textarea>
                        </label>
                        <p class="text-[11px] font-semibold leading-5 text-brand-muted">Alasan tercatat pada riwayat audit. Bukti upload tidak diwajibkan untuk penambahan Owner-attested.</p>
                        <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full sm:w-auto" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                            <span x-show="!submitting">Tinjau penambahan</span>
                            <span x-cloak x-show="submitting">Membuat preview…</span>
                        </x-admin-dashboard.button>
                    </form>
                    @else
                        <x-admin-dashboard.notice tone="warning" class="mt-4" role="status">Penambahan saldo dinonaktifkan karena proyeksi Customer tidak cocok dengan ledger.</x-admin-dashboard.notice>
                    @endif
                </section>
            @endif
        </x-admin-dashboard.card>

        @if ($previewUnavailable)
            <x-admin-dashboard.notice tone="warning" role="status">Preview tidak tersedia untuk sesi Owner ini. Cari Customer dan buat preview baru.</x-admin-dashboard.notice>
        @endif

        @if ($preview)
            @php($previewExpired = $preview->state === 'pending' && now()->gte($preview->expires_at))
            @php($previewReversal = $preview->category === 'reversal')
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-2xl" aria-label="{{ $previewReversal ? 'Preview reversal saldo' : 'Preview penambahan saldo' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Preview {{ $previewReversal ? 'reversal' : 'penambahan' }} IDR</p>
                        <h2 class="mt-1 text-lg font-black">Periksa sebelum konfirmasi</h2>
                    </div>
                    <x-admin-dashboard.badge :tone="$preview->state === 'applied' ? 'success' : ($previewExpired || $preview->state !== 'pending' ? 'warning' : 'blue')" dot>
                        {{ match ($preview->state) { 'pending' => $previewExpired ? 'KEDALUWARSA' : 'MENUNGGU PASSWORD OWNER', 'applied' => 'DITERAPKAN', 'cancelled' => 'DIBATALKAN', 'rejected' => 'DITOLAK', 'expired' => 'KEDALUWARSA', default => strtoupper($preview->state) } }}
                    </x-admin-dashboard.badge>
                </div>
                <dl class="mt-4 grid gap-3 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Penerima</dt>
                        <dd class="mt-1 break-words font-black">{{ $preview->username ? '@'.$preview->username : 'Customer Telegram' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Telegram ID</dt>
                        <dd class="mt-1 break-all font-mono font-black">{{ $preview->telegram_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">{{ $previewReversal ? 'Jumlah reversal' : 'Jumlah tambahan' }}</dt>
                        <dd class="mt-1 text-lg font-black">Rp {{ number_format($preview->amount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Alasan</dt>
                        <dd class="mt-1 break-words text-sm font-semibold">{{ $preview->reason }}</dd>
                    </div>
                    @if ($previewReversal)
                        <div class="sm:col-span-2">
                            <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Adjustment asal</dt>
                            <dd class="mt-1 break-words text-sm font-semibold">Rp {{ number_format($previewOriginal?->amount ?? $preview->amount, 0, ',', '.') }} · {{ $previewOriginal?->reason ?? 'Adjustment asal' }}</dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Batas konfirmasi</dt>
                        <dd class="mt-1 text-sm font-semibold">{{ $preview->expires_at->timezone(config('app.timezone'))->format('d M Y, H:i T') }}</dd>
                    </div>
                </dl>

                @if ($preview->state === 'pending' && ! $previewExpired && $previewBalance?->isAvailable())
                    <form
                        method="POST"
                        action="{{ route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $preview->id]) }}"
                        class="mt-4 space-y-3"
                        aria-label="{{ $previewReversal ? 'Konfirmasi reversal dengan password Owner' : 'Konfirmasi penambahan dengan password Owner' }}"
                        x-data="{ submitting: false }"
                        x-on:submit.prevent="if (!submitting) { submitting = true; $nextTick(() => $el.submit()); }"
                    >
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $preview->fingerprint }}">
                        <input type="hidden" name="version" value="{{ $preview->version }}">
                        <label class="block text-xs font-bold text-neo-ink" for="balance-adjustment-password">
                            Password Owner untuk tindakan ini
                            <input id="balance-adjustment-password" name="password" type="password" autocomplete="current-password" class="neo-input mt-1 w-full bg-white px-3 py-2.5 text-base font-semibold" required>
                        </label>
                        <p class="text-[11px] font-semibold leading-5 text-brand-muted">Password diminta setiap kali konfirmasi dan tidak disimpan pada preview.</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                                <span x-show="!submitting">Konfirmasi {{ $previewReversal ? 'reversal' : 'penambahan' }}</span>
                                <span x-cloak x-show="submitting">Memproses…</span>
                            </x-admin-dashboard.button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('admin.store-balance.adjustments.previews.cancel', ['adjustment' => $preview->id]) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $preview->fingerprint }}">
                        <input type="hidden" name="version" value="{{ $preview->version }}">
                        <button type="submit" class="w-full rounded-xl px-3 py-2 text-sm font-bold text-brand-slate-dark underline sm:w-auto">Batalkan preview</button>
                    </form>
                @elseif ($preview->state === 'applied')
                    <x-admin-dashboard.notice tone="success" class="mt-4" role="status">{{ $previewReversal ? 'Reversal ini sudah diterapkan. Konfirmasi ulang tidak mendebit saldo kedua kali.' : 'Penambahan ini sudah diterapkan. Pengiriman ulang konfirmasi tidak membuat kredit kedua.' }}</x-admin-dashboard.notice>
                @elseif ($preview->state === 'pending' && ! $previewExpired)
                    <x-admin-dashboard.notice tone="warning" class="mt-4" role="status">Saldo Customer tidak dapat diverifikasi dengan ledger. Konfirmasi ditahan sampai rekonsiliasi dilakukan.</x-admin-dashboard.notice>
                @elseif ($previewExpired)
                    <x-admin-dashboard.notice tone="warning" class="mt-4" role="status">Preview kedaluwarsa. Cari Customer dan buat preview baru untuk melanjutkan.</x-admin-dashboard.notice>
                @endif
            </x-admin-dashboard.card>
        @endif

        @if ($customer && $history)
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-2xl" aria-label="Riwayat penambahan saldo Customer">
                <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Riwayat IDR Customer</p>
                <h2 class="mt-1 text-lg font-black">Riwayat penambahan dan reversal IDR</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($history as $entry)
                        @php($entryDirection = $entry->ledgerEntry?->kind?->direction() ?? ($entry->category === 'reversal' ? -1 : 1))
                        <article class="rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3" data-adjustment-state="{{ $entry->state }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-black">{{ $entryDirection < 0 ? '−' : '+' }}Rp {{ number_format($entry->amount, 0, ',', '.') }}</p>
                                    <p class="mt-1 text-[11px] font-semibold text-brand-muted">{{ $entry->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i T') }} · {{ strtoupper(str_replace('_', ' ', $entry->category)) }}</p>
                                </div>
                                <x-admin-dashboard.badge :tone="$entry->state === 'applied' ? 'success' : ($entry->state === 'pending' ? 'warning' : 'slate')">
                                    {{ match ($entry->state) { 'pending' => 'Menunggu konfirmasi', 'applied' => 'Diterapkan', 'cancelled' => 'Dibatalkan', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa', default => ucfirst($entry->state) } }}
                                </x-admin-dashboard.badge>
                            </div>
                            <p class="mt-2 break-words text-sm font-semibold">{{ $entry->reason }}</p>
                            @if ($entry->ledger_entry_id)
                                <p class="mt-2 text-[11px] font-semibold text-brand-muted">Ledger entry #{{ $entry->ledger_entry_id }}</p>
                            @endif
                            @if (in_array($entry->id, $reversibleAdjustmentIds, true))
                                <form
                                    method="POST"
                                    action="{{ route('admin.store-balance.adjustments.reversals.store') }}"
                                    class="mt-3"
                                    aria-label="Reversal penuh penyesuaian ini"
                                    x-data="{ submitting: false }"
                                    x-on:submit.prevent="if (!submitting) { submitting = true; $nextTick(() => $el.submit()); }"
                                >
                                    @csrf
                                    <input type="hidden" name="source_id" value="{{ $entry->id }}">
                                    <input type="hidden" name="telegram_id" value="{{ (string) $customer->telegram_id }}">
                                    <button type="submit" class="rounded-xl border-2 border-neo-ink/15 bg-white px-3 py-1.5 text-xs font-black text-brand-slate-dark transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-neo-focus" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                                        <span x-show="!submitting">Reversal penuh</span>
                                        <span x-cloak x-show="submitting">Membuat preview…</span>
                                    </button>
                                </form>
                            @endif
                        </article>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada permintaan penambahan saldo untuk Customer ini." />
                    @endforelse
                </div>
                @if ($history->hasPages())
                    <nav class="mt-4" aria-label="Halaman riwayat saldo">{{ $history->links() }}</nav>
                @endif
            </x-admin-dashboard.card>
        @endif

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-2xl" aria-label="Customer dengan saldo Rupiah positif">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Pemantauan Store Balance</p>
                    <h2 class="mt-1 text-lg font-black">Customer dengan saldo Rupiah positif</h2>
                </div>
                <x-admin-dashboard.badge tone="green" dot>IDR</x-admin-dashboard.badge>
            </div>
            <p class="mt-2 text-[11px] font-semibold leading-5 text-brand-muted">Daftar menampilkan account yang memiliki saldo tersedia di atas nol. Account bernilai nol tetap tersimpan untuk menjaga riwayat ledger.</p>
            <div class="mt-4 space-y-2">
                @forelse ($positiveBalances as $balanceItem)
                    @php($accountCustomer = $balanceItem['customer'])
                    @php($balanceSnapshot = $balanceItem['snapshot'])
                    @if ($accountCustomer && $accountCustomer->telegram_id !== null)
                        <a href="{{ route('admin.store-balance.adjustments.index', ['telegram_id' => (string) $accountCustomer->telegram_id]) }}" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3 transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-neo-focus" aria-label="Lihat saldo {{ $accountCustomer->first_name ?: 'Customer' }}">
                            <span class="min-w-0">
                                <span class="block break-words font-black">{{ $accountCustomer->first_name ?: 'Customer Telegram' }} {{ $accountCustomer->username ? '· @'.$accountCustomer->username : '' }}</span>
                                <span class="mt-1 block break-all font-mono text-[11px] font-semibold text-brand-muted">{{ (string) $accountCustomer->telegram_id }}</span>
                            </span>
                            <span class="font-display text-sm font-black">{{ $balanceSnapshot->isAvailable() ? 'Rp '.number_format($balanceSnapshot->balance(), 0, ',', '.') : 'Perlu rekonsiliasi' }}</span>
                        </a>
                    @elseif ($accountCustomer)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3">
                            <span class="min-w-0">
                                <span class="block break-words font-black">{{ $accountCustomer->first_name ?: 'Customer Web Storefront' }}</span>
                                <span class="mt-1 block text-[11px] font-semibold text-brand-muted">Customer ini belum memiliki Telegram ID.</span>
                            </span>
                            <span class="font-display text-sm font-black">{{ $balanceSnapshot->isAvailable() ? 'Rp '.number_format($balanceSnapshot->balance(), 0, ',', '.') : 'Perlu rekonsiliasi' }}</span>
                        </div>
                    @endif
                @empty
                    <x-admin-dashboard.empty-state title="Belum ada Customer dengan saldo Rupiah positif." />
                @endforelse
            </div>
            @if ($positiveBalances->hasPages())
                <nav class="mt-4" aria-label="Halaman Customer bersaldo positif">{{ $positiveBalances->links() }}</nav>
            @endif
        </x-admin-dashboard.card>
    </main>
</body>
</html>
