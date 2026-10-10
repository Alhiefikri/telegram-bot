<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Kelola saldo Customer · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
<x-admin-dashboard.linked-shell active-tab="stats" active-navigation-id="balance-adjustments" title="Kelola saldo Customer">
    <div class="space-y-4">
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
        <a href="{{ route('admin.dashboard', ['tab' => 'stats']) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke Ringkasan Admin</a>

        {{-- Status and validation errors are rendered in the shared shell above. --}}

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Tambah saldo Rupiah">
            <p class="text-xs font-medium text-admin-muted">Store Balance · IDR</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Tambah saldo Customer</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-admin-muted">
                Cari Customer memakai Telegram ID numerik exact. Periksa identitas dan nominal pada preview, lalu konfirmasi dengan password Owner. Form ini menambahkan nominal ke saldo; form ini tidak menetapkan saldo akhir.
            </p>

            <form method="GET" action="{{ route('admin.store-balance.adjustments.index') }}" class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" aria-label="Cari Customer berdasarkan Telegram ID">
                <div class="block text-xs font-medium text-admin-ink">
                    <x-admin-dashboard.field-label for="balance-adjustment-telegram-id">Telegram ID</x-admin-dashboard.field-label>
                    <input
                        id="balance-adjustment-telegram-id"
                        name="telegram_id"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        autocomplete="off"
                        value="{{ old('telegram_id', $telegramId) }}"
                        class="mt-1 min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-base font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
                        aria-describedby="balance-adjustment-id-help"
                        required
                    >
                </div>
                <p id="balance-adjustment-id-help" class="text-xs font-medium leading-5 text-admin-muted sm:col-span-2 sm:row-start-2">ID dicocokkan persis dengan Customer yang sudah terdaftar. Username hanya informasi tampilan.</p>
                <x-admin-dashboard.button type="submit" variant="ink" size="sm" class="w-full sm:col-start-2 sm:row-start-1 sm:w-auto">Cari Customer</x-admin-dashboard.button>
            </form>

            @if ($lookupError)
                <x-admin-dashboard.notice tone="warning" class="mt-4" role="status">{{ $lookupError }}</x-admin-dashboard.notice>
            @endif

            @if ($customer)
                @php($customerId = (string) $customer->telegram_id)
                <section class="mt-5 rounded-lg border border-admin-line bg-admin-surface-muted p-4" aria-label="Customer ditemukan">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium text-admin-muted">Customer</p>
                            <p class="mt-1 break-words text-base font-semibold text-admin-ink">{{ $customer->first_name ?: 'Customer Telegram' }}</p>
                            <p class="mt-1 text-sm font-medium text-admin-muted">{{ $customer->username ? '@'.$customer->username : 'Username belum tersedia' }}</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="text-xs font-medium text-admin-muted">Telegram ID · tidak dapat diubah</p>
                            <p class="mt-1 break-all font-mono text-base font-semibold text-admin-ink" data-customer-telegram-id="{{ $customerId }}">{{ $customerId }}</p>
                            @if ($customerBalance?->isAvailable())
                                <p class="mt-1 text-sm font-medium text-admin-muted">Saldo Rupiah saat ini <span class="font-semibold text-admin-ink">Rp {{ number_format($customerBalance->balance(), 0, ',', '.') }}</span></p>
                            @else
                                <p class="mt-1 text-sm font-semibold text-neo-danger-text">Saldo Rupiah tidak dapat ditampilkan sampai ledger direkonsiliasi.</p>
                            @endif
                        </div>
                    </div>

                    @if ($customerBalance?->isAvailable())
                    <form
                        method="POST"
                        action="{{ route('admin.store-balance.adjustments.previews.store') }}"
                        class="mt-4 grid gap-3 border-t border-admin-line pt-4"
                        aria-label="Isi penambahan saldo"
                        x-data="{ submitting: false }"
                        x-on:submit.prevent="if (!submitting) { submitting = true; $nextTick(() => $el.submit()); }"
                    >
                        @csrf
                        <input type="hidden" name="telegram_id" value="{{ $customerId }}">
                        <div class="block text-xs font-medium text-admin-ink">
                            <x-admin-dashboard.field-label for="balance-adjustment-amount">Jumlah tambahan Rupiah</x-admin-dashboard.field-label>
                            <input
                                id="balance-adjustment-amount"
                                name="amount"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                autocomplete="off"
                                value="{{ old('amount') }}"
                                class="mt-1 min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-base font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
                                required
                            >
                        </div>
                        <p class="-mt-2 text-xs font-medium leading-5 text-admin-muted">Masukkan angka Rupiah positif tanpa titik, koma, atau desimal.</p>
                        <div class="block text-xs font-medium text-admin-ink">
                            <x-admin-dashboard.field-label for="balance-adjustment-reason">Alasan penambahan</x-admin-dashboard.field-label>
                            <textarea id="balance-adjustment-reason" name="reason" rows="3" maxlength="500" class="mt-1 min-h-28 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" required>{{ old('reason') }}</textarea>
                        </div>
                        <p class="text-xs font-medium leading-5 text-admin-muted">Alasan tercatat pada riwayat audit. Bukti upload tidak diwajibkan untuk penambahan Owner-attested.</p>
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
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="{{ $previewReversal ? 'Preview reversal saldo' : 'Preview penambahan saldo' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-medium text-admin-muted">Preview {{ $previewReversal ? 'reversal' : 'penambahan' }} IDR</p>
                        <h2 class="mt-1 text-lg font-semibold text-admin-ink">Periksa sebelum konfirmasi</h2>
                    </div>
                    <x-admin-dashboard.badge :tone="$preview->state === 'applied' ? 'success' : ($previewExpired || $preview->state !== 'pending' ? 'warning' : 'blue')" dot>
                        {{ match ($preview->state) { 'pending' => $previewExpired ? 'KEDALUWARSA' : 'MENUNGGU PASSWORD OWNER', 'applied' => 'DITERAPKAN', 'cancelled' => 'DIBATALKAN', 'rejected' => 'DITOLAK', 'expired' => 'KEDALUWARSA', default => strtoupper($preview->state) } }}
                    </x-admin-dashboard.badge>
                </div>
                <dl class="mt-4 grid gap-3 rounded-lg border border-admin-line bg-admin-surface-muted p-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium text-admin-muted">Penerima</dt>
                        <dd class="mt-1 break-words font-semibold text-admin-ink">{{ $preview->username ? '@'.$preview->username : 'Customer Telegram' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-admin-muted">Telegram ID</dt>
                        <dd class="mt-1 break-all font-mono font-semibold text-admin-ink">{{ $preview->telegram_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-admin-muted">{{ $previewReversal ? 'Jumlah reversal' : 'Jumlah tambahan' }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-admin-ink">Rp {{ number_format($preview->amount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-admin-muted">Alasan</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-admin-ink">{{ $preview->reason }}</dd>
                    </div>
                    @if ($previewReversal)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium text-admin-muted">Adjustment asal</dt>
                            <dd class="mt-1 break-words text-sm font-medium text-admin-ink">Rp {{ number_format($previewOriginal?->amount ?? $preview->amount, 0, ',', '.') }} · {{ $previewOriginal?->reason ?? 'Adjustment asal' }}</dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium text-admin-muted">Batas konfirmasi</dt>
                        <dd class="mt-1 text-sm font-medium text-admin-ink">{{ $preview->expires_at->timezone(config('app.timezone'))->format('d M Y, H:i T') }}</dd>
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
                        <div class="block text-xs font-medium text-admin-ink">
                            <x-admin-dashboard.field-label for="balance-adjustment-password">Password Owner untuk tindakan ini</x-admin-dashboard.field-label>
                            <input id="balance-adjustment-password" name="password" type="password" autocomplete="current-password" class="mt-1 min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-base font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" required>
                        </div>
                        <p class="text-xs font-medium leading-5 text-admin-muted">Password diminta setiap kali konfirmasi dan tidak disimpan pada preview.</p>
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
                        <button type="submit" class="min-h-11 w-full px-3 py-2 text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline sm:w-auto">Batalkan preview</button>
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
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Riwayat penambahan saldo Customer">
                <p class="text-xs font-medium text-admin-muted">Riwayat IDR Customer</p>
                <h2 class="mt-1 text-lg font-semibold text-admin-ink">Riwayat penambahan dan reversal IDR</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($history as $entry)
                        @php($entryDirection = $entry->ledgerEntry?->kind?->direction() ?? ($entry->category === 'reversal' ? -1 : 1))
                        <article class="rounded-lg border border-admin-line bg-admin-surface-muted p-3" data-adjustment-state="{{ $entry->state }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-admin-ink">{{ $entryDirection < 0 ? '−' : '+' }}Rp {{ number_format($entry->amount, 0, ',', '.') }}</p>
                                    <p class="mt-1 text-xs font-medium text-admin-muted">{{ $entry->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i T') }} · {{ strtoupper(str_replace('_', ' ', $entry->category)) }}</p>
                                </div>
                                <x-admin-dashboard.badge :tone="$entry->state === 'applied' ? 'success' : ($entry->state === 'pending' ? 'warning' : 'slate')">
                                    {{ match ($entry->state) { 'pending' => 'Menunggu konfirmasi', 'applied' => 'Diterapkan', 'cancelled' => 'Dibatalkan', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa', default => ucfirst($entry->state) } }}
                                </x-admin-dashboard.badge>
                            </div>
                            <p class="mt-2 break-words text-sm font-medium text-admin-ink">{{ $entry->reason }}</p>
                            @if ($entry->ledger_entry_id)
                                <p class="mt-2 text-xs font-medium text-admin-muted">Ledger entry #{{ $entry->ledger_entry_id }}</p>
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
                                    <x-admin-dashboard.button type="submit" variant="neutral" size="sm" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                                        <span x-show="!submitting">Reversal penuh</span>
                                        <span x-cloak x-show="submitting">Membuat preview…</span>
                                    </x-admin-dashboard.button>
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

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Customer dengan saldo Rupiah positif">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-admin-muted">Pemantauan Store Balance</p>
                    <h2 class="mt-1 text-lg font-semibold text-admin-ink">Customer dengan saldo Rupiah positif</h2>
                </div>
                <x-admin-dashboard.badge tone="emerald" dot>IDR</x-admin-dashboard.badge>
            </div>
            <p class="mt-2 text-xs font-medium leading-5 text-admin-muted">Daftar menampilkan account yang memiliki saldo tersedia di atas nol. Account bernilai nol tetap tersimpan untuk menjaga riwayat ledger.</p>
            <div class="mt-4 space-y-2">
                @forelse ($positiveBalances as $balanceItem)
                    @php($accountCustomer = $balanceItem['customer'])
                    @php($balanceSnapshot = $balanceItem['snapshot'])
                    @if ($accountCustomer && $accountCustomer->telegram_id !== null)
                        <a href="{{ route('admin.store-balance.adjustments.index', ['telegram_id' => (string) $accountCustomer->telegram_id]) }}" class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3 transition-colors hover:border-admin-accent focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" aria-label="Lihat saldo {{ $accountCustomer->first_name ?: 'Customer' }}">
                            <span class="min-w-0">
                                <span class="block break-words font-semibold text-admin-ink">{{ $accountCustomer->first_name ?: 'Customer Telegram' }} {{ $accountCustomer->username ? '· @'.$accountCustomer->username : '' }}</span>
                                <span class="mt-1 block break-all font-mono text-xs font-medium text-admin-muted">{{ (string) $accountCustomer->telegram_id }}</span>
                            </span>
                            <span class="text-sm font-semibold text-admin-ink">{{ $balanceSnapshot->isAvailable() ? 'Rp '.number_format($balanceSnapshot->balance(), 0, ',', '.') : 'Perlu rekonsiliasi' }}</span>
                        </a>
                    @elseif ($accountCustomer)
                        <div class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                            <span class="min-w-0">
                                <span class="block break-words font-semibold text-admin-ink">{{ $accountCustomer->first_name ?: 'Customer Web Storefront' }}</span>
                                <span class="mt-1 block text-xs font-medium text-admin-muted">Customer ini belum memiliki Telegram ID.</span>
                            </span>
                            <span class="text-sm font-semibold text-admin-ink">{{ $balanceSnapshot->isAvailable() ? 'Rp '.number_format($balanceSnapshot->balance(), 0, ',', '.') : 'Perlu rekonsiliasi' }}</span>
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
    </div>
</x-admin-dashboard.linked-shell>
</body>
</html>
