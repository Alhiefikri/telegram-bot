<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Review USDT · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
<main class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
    <a href="{{ route('admin.dashboard', ['tab' => 'stats']) }}" class="font-bold underline">← Ringkasan Admin</a>
    <h1 class="text-2xl font-black">Review deposit USDT dan insiden</h1>
    <x-admin-dashboard.notice tone="warning">Hash publik hanya petunjuk lookup. Customer terikat receiver permanen. Invoice asli tidak berubah; deposit sub-micro atau bukti ambigu tetap unresolved, tanpa pembulatan atau grant. Review tidak mengaktifkan pendanaan produksi.</x-admin-dashboard.notice>
    @if(session('status'))
        <x-admin-dashboard.notice tone="success" role="status">{{ session('status') }}</x-admin-dashboard.notice>
    @endif
    @if($errors->any())
        <x-admin-dashboard.notice tone="danger" role="alert">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </x-admin-dashboard.notice>
    @endif

    @if($preview)
        <x-admin-dashboard.card as="section" padding="compact" aria-label="Preview resolusi USDT">
            <h2 class="text-lg font-black">Periksa resolusi sebelum konfirmasi</h2>
            <p class="mt-2 break-all">Target {{ $preview->top_up_id }} · {{ $preview->decision }}</p>
            <p class="mt-2">Customer #{{ $previewOriginal->customer_id }} · Telegram {{ $previewOriginal->telegram_id }} · kredit awal {{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($previewOriginal->amount_micro)->decimal() }} USDT · fee awal {{ $previewOriginal->fee_micro }} micro-USDT</p>
            <p class="mt-2 font-bold">Nominal deposit terverifikasi {{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($preview->amount_micro)->decimal() }} USDT</p>
            @if($preview->decision !== 'externally_refunded')
                <p class="mt-2 font-bold">Kredit disetujui setelah fee awal {{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($preview->amount_micro - $previewOriginal->fee_micro)->decimal() }} USDT</p>
            @else
                <p class="mt-2 font-bold">Seluruh nominal deposit dikembalikan secara eksternal; tidak ada kredit saldo.</p>
            @endif
            <p class="mt-2">{{ $preview->reason }}</p>
            <p class="mt-2 text-sm">Bukti transfer {{ substr($preview->transaction_hash, 0, 10) }}…{{ substr($preview->transaction_hash, -6) }} · berlaku sampai {{ $preview->expires_at }}</p>
            <p class="mt-2 text-sm">Invoice asli tidak berubah. Satu resolusi menutup settlement berikutnya; uang tambahan tetap unresolved.</p>
            @if(now()->lt($preview->expires_at))
                <form method="POST" action="{{ route('admin.store-balance.usdt-review.previews.confirm', $preview->id) }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $preview->fingerprint }}">
                    <input type="hidden" name="version" value="{{ $preview->version }}">
                    <label class="block font-bold" for="review-password">Password Owner untuk tindakan ini</label>
                    <x-admin-dashboard.input id="review-password" type="password" name="password" autocomplete="current-password" maxlength="1024" required />
                    <x-admin-dashboard.button type="submit" variant="primary">Konfirmasi resolusi</x-admin-dashboard.button>
                </form>
            @else
                <p class="mt-3 font-bold">Preview kedaluwarsa. Buat preview baru dengan bukti terkini.</p>
            @endif
        </x-admin-dashboard.card>
    @endif

    @if($recoveryPreview)
        <x-admin-dashboard.card as="section" padding="compact" aria-label="Preview recovery insiden">
            <h2 class="text-lg font-black">Otorisasi recovery insiden #{{ $recoveryPreview->incident_id }}</h2>
            <p class="mt-2">Customer #{{ $recoveryIncident->customer_id }} · exposure {{ $recoveryIncident->exposure_micro }} micro-USDT · terpakai {{ $recoveryIncident->spent_exposure_micro }} micro-USDT</p>
            <p class="mt-2">{{ $recoveryPreview->reason }}</p>
            <p class="mt-2 text-sm">Ini hanya membuka case recovery. Kerugian tetap unresolved; saldo tidak didebit dan freeze tidak dilepas.</p>
            @if(now()->lt($recoveryPreview->expires_at))
                <form method="POST" action="{{ route('admin.store-balance.usdt-review.recovery-previews.confirm', $recoveryPreview->id) }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $recoveryPreview->fingerprint }}">
                    <label class="block font-bold" for="recovery-password">Password Owner untuk recovery</label>
                    <x-admin-dashboard.input id="recovery-password" type="password" name="password" autocomplete="current-password" maxlength="1024" required />
                    <x-admin-dashboard.button type="submit" variant="warning">Otorisasi case recovery</x-admin-dashboard.button>
                </form>
            @else
                <p class="mt-3 font-bold">Preview recovery kedaluwarsa.</p>
            @endif
        </x-admin-dashboard.card>
    @endif

    <x-admin-dashboard.card as="section" padding="compact" aria-label="Target top-up USDT">
        <h2 class="text-lg font-black">Target top-up</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Target / Customer</th><th class="p-2">Kredit / payable</th><th class="p-2">Status</th><th class="p-2">Review</th></tr></thead>
                <tbody>
                @forelse($targets as $target)
                    <tr class="border-t border-neo-ink/15">
                        <td class="p-2"><span class="break-all font-mono">{{ $target->id }}</span><br>Customer #{{ $target->customer_id }} · Telegram {{ $target->telegram_id }}</td>
                        <td class="p-2">{{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($target->amount_micro)->decimal() }} / {{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($target->payable_micro)->decimal() }} USDT</td>
                        <td class="p-2">{{ $target->resolution_kind ?? $target->state }}<br>Persiapan: {{ $target->preparation_state }}<br>{{ $target->last_error }}</td>
                        <td class="p-2"><a class="font-bold underline" href="{{ route('admin.store-balance.usdt-review.index', ['top_up' => $target->id]) }}">Tinjau</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-3">Belum ada target top-up.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $targets->links() }}</div>
    </x-admin-dashboard.card>

    @if($selected)
        <x-admin-dashboard.card as="section" padding="compact" aria-label="Bukti target">
            <h2 class="text-lg font-black">Target {{ $selected->id }}</h2>
            <p class="mt-2">Customer #{{ $selected->customer_id }} · kredit awal {{ \App\Services\UsdtBalance\UsdtMicroAmount::fromUnits($selected->amount_micro)->decimal() }} USDT · fee {{ $selected->fee_micro }} micro-USDT</p>
            <h3 class="mt-4 font-bold">Riwayat pemeriksaan authoritative</h3>
            @forelse($evidence as $item)
                <p class="mt-2 text-sm">Versi {{ $item->version }} · {{ $item->status }} · {{ $item->reason }} · {{ $item->created_at }}</p>
            @empty
                <p class="mt-2 text-sm">Belum ada pemeriksaan. Creation unknown bukan bukti dana diterima.</p>
            @endforelse
            <div class="mt-3">{{ $evidence->links() }}</div>
            <form method="POST" action="{{ route('admin.store-balance.usdt-review.previews.store') }}" class="mt-5 grid gap-3 border-t border-neo-ink/15 pt-4" aria-label="Tinjau resolusi deposit">
                @csrf
                <input type="hidden" name="top_up_id" value="{{ $selected->id }}">
                <label class="block font-bold" for="review-decision">Keputusan</label>
                <x-admin-dashboard.select id="review-decision" name="decision" required>
                    <option value="settled">Settlement nominal terverifikasi (termasuk revisi)</option>
                    <option value="resolved_by_compensation">Kompensasi deposit terverifikasi</option>
                    <option value="externally_refunded">Pengembalian eksternal yang sudah terbukti</option>
                </x-admin-dashboard.select>
                <label class="block font-bold" for="review-amount">Nominal resolusi USDT (exact, maksimal 6 desimal)</label>
                <x-admin-dashboard.input id="review-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" maxlength="32" required />
                <label class="block font-bold" for="review-hash">Transaction hash deposit</label>
                <x-admin-dashboard.input id="review-hash" name="transaction_hash" type="text" autocomplete="off" maxlength="66" required />
                <label class="block font-bold" for="review-reason">Alasan resolusi</label>
                <x-admin-dashboard.textarea id="review-reason" name="reason" :rows="3" maxlength="1000" required />
                <label class="block font-bold" for="refund-hash">Transaction hash pengembalian (hanya external refund)</label>
                <x-admin-dashboard.input id="refund-hash" name="refund_transaction_hash" type="text" autocomplete="off" maxlength="66" />
                <label class="block font-bold" for="refund-authorization">ID persetujuan Customer terautentikasi (hanya external refund)</label>
                <x-admin-dashboard.input id="refund-authorization" name="refund_authorization_id" type="text" autocomplete="off" maxlength="36" />
                <p class="text-sm">Owner tidak dapat memilih alamat pengembalian. Hash/screenshot bukan bukti. Preview memeriksa ulang finalitas, receiver, token dan nominal melalui RPC; bukti ambigu ditahan.</p>
                <x-admin-dashboard.button type="submit" variant="ink">Buat preview resolusi</x-admin-dashboard.button>
            </form>
        </x-admin-dashboard.card>
    @endif

    <x-admin-dashboard.card as="section" padding="compact" aria-label="Observasi receiver dan orphan deposit">
        <h2 class="text-lg font-black">Observasi receiver termasuk deposit tanpa invoice</h2>
        <p class="mt-2 text-sm">Raw token units dipertahankan exact. Observasi bukan receipt claim atau otorisasi kredit. Identitas Customer berasal dari receiver, bukan pengirim hash.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Observasi / Customer</th><th class="p-2">Raw token units</th><th class="p-2">Block / review</th></tr></thead>
                <tbody>
                @forelse($observations as $item)
                    <tr class="border-t border-neo-ink/15"><td class="p-2">#{{ $item->id }} · Customer #{{ $item->customer_id }} · receiver #{{ $item->receiver_id }}</td><td class="break-all p-2 font-mono">{{ $item->amount_units }}</td><td class="p-2">{{ $item->block_number }} · {{ $item->review_reason ?? 'observed' }}</td></tr>
                @empty
                    <tr><td colspan="3" class="p-3">Belum ada observasi receiver.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $observations->links() }}</div>
    </x-admin-dashboard.card>

    <x-admin-dashboard.card as="section" padding="compact" aria-label="Insiden reorg dan exposure treasury">
        <h2 class="text-lg font-black">Insiden reorg · kerugian belum terselesaikan</h2>
        <p class="mt-2 text-sm">History tetap utuh. Tidak ada saldo negatif, debit otomatis Customer, atau aset treasury sintetis.</p>
        @forelse($incidents as $incident)
            <section class="mt-4 border-t border-neo-ink/15 pt-3">
                <h3 class="font-bold">Insiden #{{ $incident->id }} · Customer #{{ $incident->customer_id }} · {{ $incident->reason }}</h3>
                <p class="mt-2 text-sm">Exposure {{ $incident->exposure_micro }} micro-USDT · tersedia {{ $incident->available_exposure_micro }} · sudah terpakai {{ $incident->spent_exposure_micro }}</p>
                @if($incident->recovery_status)
                    <p class="mt-2 font-bold">Recovery: {{ $incident->recovery_status }}</p>
                @else
                    <form method="POST" action="{{ route('admin.store-balance.usdt-review.recovery-previews.store') }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="incident_id" value="{{ $incident->id }}">
                        <label class="block font-bold" for="recovery-reason-{{ $incident->id }}">Alasan case recovery</label>
                        <x-admin-dashboard.textarea id="recovery-reason-{{ $incident->id }}" name="reason" :rows="2" maxlength="500" required />
                        <x-admin-dashboard.button type="submit" variant="warning">Tinjau otorisasi recovery</x-admin-dashboard.button>
                    </form>
                @endif
            </section>
        @empty
            <p class="mt-3">Belum ada insiden reorg.</p>
        @endforelse
        <div class="mt-3">{{ $incidents->links() }}</div>
    </x-admin-dashboard.card>
</main>
</body>
</html>
