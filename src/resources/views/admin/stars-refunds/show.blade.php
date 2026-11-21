<x-admin-dashboard.linked-shell active-tab="settings" active-navigation-id="stars-refunds" title="Refund Telegram Stars">
    <section class="space-y-4">
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @error('reason') <p role="alert">{{ $message }}</p> @enderror
        @error('confirm') <p role="alert">{{ $message }}</p> @enderror
        @error('password') <p role="alert">{{ $message }}</p> @enderror

        @if (isset($charge))
            <a class="underline" href="{{ route('admin.stars-refunds.index') }}">Kembali ke daftar charge dan review</a>
            <dl class="space-y-2">
                <div><dt>Invoice</dt><dd>{{ $charge['invoice'] }}</dd></div>
                <div><dt>Pembeli Telegram asli</dt><dd>{{ $charge['buyer'] }}</dd></div>
                <div><dt>Charge asli (tersamarkan)</dt><dd>{{ $charge['charge_label'] }} · receipt #{{ $charge['receipt'] }}</dd></div>
                <div><dt>Total refund penuh</dt><dd>{{ $charge['total'] }} XTR</dd></div>
                <div><dt>Status pembayaran</dt><dd>{{ $payment->status->value }}</dd></div>
            </dl>
            @if ($charge['claim'])
                <p>Status refund: <strong>{{ $charge['claim']->state }}</strong>
                    @if ($charge['claim']->confirmation_source)
                        · {{ $charge['claim']->confirmation_source }}
                    @endif
                </p>
                <p>Diminta {{ $charge['claim']->requested_at }} oleh Owner #{{ $charge['claim']->actor_user_id }}: {{ $charge['claim']->reason }}</p>
                @if ($charge['claim']->state !== 'confirmed')
                    <p>Jangan kirim ulang. Rekonsiliasi bukti refund Telegram; pesanan belum dicatat sebagai direfund.</p>
                @endif
            @elseif ($charge['eligible'])
                <form method="POST" action="{{ route('admin.stars-refunds.store', $payment) }}" class="space-y-3">
                    @csrf
                    <label for="reason">Alasan audit (jangan masukkan kredensial atau data pembayaran)</label>
                    <textarea class="block w-full" id="reason" name="reason" maxlength="500" required>{{ old('reason') }}</textarea>
                    <label class="block">Password Owner <input type="password" name="password" autocomplete="current-password" required></label>
                    <label class="block"><input type="checkbox" name="confirm" value="1" required> Saya mengonfirmasi refund penuh {{ $charge['total'] }} XTR ke pembayaran Telegram asli.</label>
                    <x-admin-dashboard.button type="submit" variant="neutral">Kirim refund penuh</x-admin-dashboard.button>
                </form>
            @else
                <p>Charge tidak memenuhi syarat refund atau bukti reversal sudah ada. Periksa review.</p>
            @endif
        @else
            <p>Angka di bawah hanya Telegram Stars. Ini bukan kas IDR/USDT atau saldo yang dapat dicairkan.</p>
            <dl class="grid gap-2 sm:grid-cols-2">
                @foreach ($counts as $metric => $count)
                    <div><dt>{{ $metric }}</dt><dd>{{ $count }}</dd></div>
                @endforeach
            </dl>
            <h2>Charge asli (pending/review refund di atas)</h2>
            <ul class="space-y-2">
                @forelse ($charges as $entry)
                    <li><a class="underline" href="{{ route('admin.stars-refunds.show', $entry->id) }}">{{ $entry->invoice_number }}</a>
                        · {{ $entry->total_xtr }} XTR · {{ $entry->status }} · refund {{ $entry->state ?? 'belum diminta' }}{{ $entry->late ? ' · late payment' : '' }}</li>
                @empty
                    <li>Belum ada charge terverifikasi.</li>
                @endforelse
            </ul>
            {{ $charges->links() }}
            <h2>Bukti yang perlu ditinjau (15 terakhir per jenis)</h2>
            <ul class="space-y-2">
                @forelse ($reviews as $review)
                    <li>{{ $review->created_at }} · {{ $review->reason }}
                        @if ($review->payment_id) · payment {{ $review->payment_id }} @endif
                        @if ($review->telegram_update_id) · inbox #{{ $review->telegram_update_id }} @endif
                    </li>
                @empty
                    <li>Tidak ada bukti review.</li>
                @endforelse
            </ul>
        @endif
    </section>
</x-admin-dashboard.linked-shell>
