@php
    $purchase = $fulfillment->currentPurchase;
    $supplier = $fulfillment->reservedSupplier ?? $purchase?->supplier;
    $held = $fulfillment->reserved_cost_micros;
    $remaining = $fulfillment->remaining_quantity;
    $active = $fulfillment->order->payment_status->value === 'paid' && in_array($fulfillment->order->order_status->value, ['processing', 'completed'], true);
    $canDeliver = $active && $fulfillment->state === 'review' && $remaining > 0 && ! $purchase?->lease_expires_at?->isFuture();
    $manualOnly = $purchase?->last_error === 'manual_delivery' && $purchase?->dispatched_at === null;
    $canResolve = $active && $held > 0 && in_array($fulfillment->state, ['review', 'delivered'], true)
        && ($purchase === null || ($purchase->committed_at === null && $purchase->supplier_order_id === null && $purchase->actual_cost_micros === null
            && ! $purchase->lease_expires_at?->isFuture() && ($manualOnly || in_array($purchase->state, ['ambiguous', 'review', 'rejected'], true))));
    $owner = request()->attributes->get('adminUser')?->role === \App\Enums\UserRole::Owner;
    $recentPassword = (int) session('auth.password_confirmed_at', 0);
    $releaseNote = $fulfillment->order->expires_at === null ? '' : ' (paling lambat '.$fulfillment->order->expires_at->timezone(config('app.timezone'))->format('d M Y H:i:s T').')';
@endphp
<dl class="mt-3 grid gap-3 rounded-lg bg-admin-surface-muted p-3 text-sm sm:grid-cols-3">
    <div><dt class="text-admin-muted">Pembayaran</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $fulfillment->order->payment_status->value === 'paid' ? 'Lunas' : 'Belum lunas' }}</dd></div>
    <div><dt class="text-admin-muted">Akun pelanggan</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $fulfillment->manualHandoffs->sum('quantity') >= $fulfillment->quantity ? 'Diserahkan di luar bot' : ($fulfillment->order->telegram_receipt_sent_at ? 'Terkirim lewat bot' : ($fulfillment->state === 'delivered' ? 'Akun siap, cek pengiriman bot' : $remaining.' akun belum dipenuhi')) }}</dd></div>
    <div><dt class="text-admin-muted">{{ $fulfillment->unpaid_reservation ? 'Kapasitas direservasi' : 'Dana ditahan' }}</dt><dd class="mt-1 font-semibold text-admin-ink">{{ $supplier?->currency }} {{ \App\Services\Suppliers\SupplierMoney::decimal($held) }}</dd></div>
</dl>
@if(($canDeliver || $canResolve) && ($recentPassword <= 0 || $recentPassword > time() || time() - $recentPassword > 900))
    <x-admin-dashboard.notice tone="warning" class="mt-3">Konfirmasi password sebelum mengisi akun. Jika sesi konfirmasi berakhir, akun yang diketik tidak disimpan. <a href="{{ route('password.confirm') }}" class="inline-flex min-h-11 items-center font-semibold underline">Konfirmasi password admin</a></x-admin-dashboard.notice>
@endif
@if($canDeliver)
    <section class="mt-4" aria-labelledby="delivery-{{ $fulfillment->id }}">
        <h4 id="delivery-{{ $fulfillment->id }}" class="font-semibold text-admin-ink">Selesaikan akun pelanggan</h4>
        <p class="mt-1 text-sm leading-6 text-admin-muted">Masukkan {{ $remaining }} akun pengganti. Bot mengirim setelah semua item invoice terpenuhi. Saldo dan hold supplier tidak berubah.</p>
        @if($fulfillment->order->customer?->telegram_id !== null)
        <form method="POST" action="{{ route('admin.suppliers.resolve-invoice', $fulfillment) }}" class="mt-3 grid gap-3" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
            @csrf
            <input type="hidden" name="action" value="send_accounts">
            <input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id ?? 0 }}">
            <x-admin-dashboard.field-label>
                Akun untuk pelanggan ({{ $remaining }} unit)
                <x-admin-dashboard.textarea name="credentials" required maxlength="64000" autocomplete="off" spellcheck="false" rows="4" class="mt-1 border-admin-muted!" placeholder="Satu akun lengkap per baris" />
                <small class="mt-1 block font-normal text-admin-muted">Satu baris berisi satu akun lengkap, misalnya email | password. Isian tidak ditampilkan kembali setelah dikirim.</small>
            </x-admin-dashboard.field-label>
            <label class="flex min-h-11 items-start gap-3 text-sm leading-6 text-admin-ink"><input type="checkbox" name="confirmed" value="1" required class="mt-1 size-5 shrink-0">Saya sudah memeriksa akun dan mengizinkan bot mengirimnya ke pelanggan invoice ini.</label>
            <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="submitting" x-text="submitting ? 'Menjadwalkan pengiriman...' : 'Kirim akun lewat bot'">Kirim akun lewat bot</x-admin-dashboard.button>
        </form>
        @else
            <p class="mt-3 text-sm text-admin-ink">Pelanggan ini belum memiliki tujuan Telegram. Pengiriman bot tidak tersedia; gunakan detail pesanan untuk jalur pengiriman pelanggan.</p>
        @endif
        <details class="mt-3">
            <summary class="min-h-11 cursor-pointer text-sm font-medium text-admin-ink">Sudah saya serahkan di luar bot</summary>
            <form method="POST" action="{{ route('admin.suppliers.resolve-invoice', $fulfillment) }}" class="mt-2 grid gap-3" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                @csrf
                <input type="hidden" name="action" value="handed_off">
                <input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id ?? 0 }}">
                <label class="flex min-h-11 items-start gap-3 text-sm leading-6 text-admin-ink"><input type="checkbox" name="confirmed" value="1" required class="mt-1 size-5 shrink-0">Saya sudah menyerahkan seluruh {{ $remaining }} akun yang tersisa. Bot tidak perlu mengirim ulang.</label>
                <x-admin-dashboard.button type="submit" variant="neutral" x-bind:disabled="submitting" x-text="submitting ? 'Mencatat penyerahan...' : 'Catat sudah diserahkan'">Catat sudah diserahkan</x-admin-dashboard.button>
            </form>
        </details>
    </section>
@endif
@if($held > 0)
    <section class="mt-4 border-t border-admin-line pt-4" aria-labelledby="supplier-funds-{{ $fulfillment->id }}">
        <h4 id="supplier-funds-{{ $fulfillment->id }}" class="font-semibold text-admin-ink">{{ $fulfillment->unpaid_reservation ? 'Status hold supplier' : 'Selesaikan saldo supplier' }}</h4>
        @if($fulfillment->unpaid_reservation)
            <p class="mt-1 text-sm leading-6 text-admin-ink">Belum ada pembelian ke supplier untuk hold ini. Kapasitas direservasi saat checkout untuk pesanan yang belum dibayar dan otomatis dilepas saat pesanan kedaluwarsa atau dibatalkan{{ $releaseNote }}. Tidak ada dana supplier yang terdebit.</p>
        @else
            <p class="mt-1 text-sm leading-6 text-admin-muted">Pengiriman akun tidak melepas dana ini. Sinkronisasi hanya membaca saldo terbaru dan tidak membatalkan hold.</p>
            @if($canResolve && $owner)
                <details class="mt-3">
                    <summary class="min-h-11 cursor-pointer text-sm font-medium text-admin-ink">Saldo supplier tidak terpotong</summary>
                    <form method="POST" action="{{ route('admin.suppliers.resolve-invoice', $fulfillment) }}" class="mt-2 grid gap-3" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                        @csrf
                        <input type="hidden" name="action" value="not_debited">
                        <input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id ?? 0 }}">
                        <p class="text-sm leading-6 text-admin-ink">Tindakan ini melepas dana invoice dan menyinkronkan supplier. Konfirmasi disimpan atas nama Anda sebagai pernyataan Owner, bukan bukti otomatis dari supplier.</p>
                        <label class="flex min-h-11 items-start gap-3 text-sm leading-6 text-admin-ink"><input type="checkbox" name="confirmed" value="1" required class="mt-1 size-5 shrink-0">Saya sudah memeriksa transaksi supplier untuk invoice ini dan memastikan tidak ada debit. Saya mengizinkan pelepasan hold.</label>
                        <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="submitting" x-text="submitting ? 'Melepas hold dan menyinkronkan...' : 'Konfirmasi tidak terpotong dan sinkronkan'">Konfirmasi tidak terpotong dan sinkronkan</x-admin-dashboard.button>
                    </form>
                </details>
            @else
                <p class="mt-3 text-sm leading-6 text-admin-ink">{{ ! $owner ? 'Hanya Owner yang dapat mengonfirmasi saldo tidak terpotong.' : 'Ada transaksi supplier yang masih berjalan atau bukti yang perlu dicocokkan. Gunakan pemeriksaan lanjutan; hold belum boleh dilepas.' }}</p>
            @endif
        @endif
    </section>
@endif
@if($supplier)
    <form method="POST" action="{{ route('admin.suppliers.resolve-invoice', $fulfillment) }}" class="mt-4" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
        @csrf
        <input type="hidden" name="action" value="sync">
        <input type="hidden" name="purchase_id" value="{{ $fulfillment->current_purchase_id ?? 0 }}">
        <x-admin-dashboard.button type="submit" variant="neutral" x-bind:disabled="submitting" x-text="submitting ? 'Menyinkronkan supplier...' : 'Sinkronkan saldo terbaru'">Sinkronkan saldo terbaru</x-admin-dashboard.button>
        <p class="mt-1 text-sm leading-6 text-admin-muted">{{ $supplier->name }}. Tidak membeli ulang, mengirim akun, atau melepas hold.</p>
        <p class="mt-1 text-sm leading-6 text-admin-ink">@if($supplier->balance_micros === null)Saldo belum tersedia.@else Saldo total {{ $supplier->currency }} {{ \App\Services\Suppliers\SupplierMoney::balanceDecimal($supplier->balance_micros) }} · Tersedia {{ $supplier->currency }} {{ \App\Services\Suppliers\SupplierMoney::decimal(max(0, $supplier->balance_micros - $supplier->reserved_balance_micros)) }}@endif</p>
        <p class="mt-1 text-sm text-admin-muted">Saldo diamati: {{ $supplier->balance_observed_at?->timezone(config('app.timezone'))->format('d M Y H:i:s T') ?? 'belum terverifikasi' }}</p>
    </form>
@endif
