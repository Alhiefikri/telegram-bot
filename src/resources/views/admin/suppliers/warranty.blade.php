@extends('admin.suppliers.layout')
@section('title', 'Klaim Gmail · Refund & penggantian')
@section('content')
<x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mb-3" aria-labelledby="supplier-warranty-title">
    <p class="text-xs font-medium text-admin-muted">Supplier</p>
    <h2 id="supplier-warranty-title" class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Klaim Gmail, refund &amp; penggantian</h2>
    <p class="mt-2 text-sm leading-6 text-admin-muted">Refund supplier memulihkan modal ke saldo supplier. Refund buyer menggunakan harga retail snapshot dan proses terpisah. Keputusan supplier dapat reversed; uang buyer tidak ditarik kembali otomatis.</p>
    <p class="mt-2 text-sm leading-6 text-admin-muted">Status terminal menghentikan polling berkala. Baca ulang (GET) tetap tersedia untuk melihat koreksi supplier, tanpa mengulang refund buyer atau membuka akun retired.</p>
    <p class="mt-2 text-sm leading-6 text-admin-muted">Klaim harus sampai ke supplier sebelum deadline. Screenshot asli hanya dibuka oleh Owner/Admin setelah konfirmasi password.</p>
</x-admin-dashboard.card>
@if($nearDeadlineUnits->isNotEmpty())
    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-3 grid gap-3">
        <h2 class="text-lg font-semibold text-admin-ink">Unit mendekati deadline klaim ({{ $nearDeadlineMinutes }} menit)</h2>
        <p class="text-sm leading-6 text-admin-muted">Belum ada klaim pada unit-unit ini. Pembeli Telegram menerima pengingat otomatis 30 menit sebelum deadline; hubungi manual bila pengingat belum dijadwalkan atau pembeli tanpa Telegram.</p>
        @foreach($nearDeadlineUnits as $unit)
            <p class="break-all text-sm text-admin-ink">
                <a class="font-medium text-admin-accent-strong underline-offset-4 hover:underline" href="{{ route('admin.orders.show', $unit->order) }}">{{ $unit->order->invoice_number }}</a>
                · akun #{{ $unit->unit_number }} · {{ $unit->orderItem->product_name }}
                · {{ $unit->refund_deadline->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}
                · {{ $unit->reminder_materialized_at !== null ? 'Pengingat diantrikan '.$unit->reminder_materialized_at->timezone(config('app.timezone'))->format('H:i T') : ($unit->order->customer?->telegram_id !== null ? 'Pengingat belum dijadwalkan' : 'Pembeli tanpa Telegram') }}
            </p>
        @endforeach
        {{ $nearDeadlineUnits->links() }}
    </x-admin-dashboard.card>
@endif
@foreach($claims as $claim)
    @php
        $refund = $refunds->get($claim->id);
    @endphp
    <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="mt-3 grid min-w-0 gap-3">
        <h2 class="break-all text-lg font-semibold text-admin-ink">{{ $claim->order->invoice_number }} · akun #{{ $claim->unit->unit_number }}</h2>
        <p class="text-sm text-admin-ink">{{ $claim->unit->orderItem->product_name }} · Deadline {{ $claim->refund_deadline->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}</p>
        <p class="text-sm text-admin-ink">Klaim: <x-admin-dashboard.badge tone="slate">{{ $claim->state }}</x-admin-dashboard.badge> · Penyelesaian buyer: <x-admin-dashboard.badge tone="slate">{{ $claim->settlement_state }}</x-admin-dashboard.badge> · Pilihan: {{ $claim->settlement_choice ?? 'belum memilih' }}</p>
        @if($claim->last_error || $claim->replacement_last_error)
            <x-admin-dashboard.notice tone="danger" class="break-all font-mono text-xs">Kode pemulihan: {{ $claim->last_error ?? $claim->replacement_last_error }}</x-admin-dashboard.notice>
        @endif
        <div class="flex flex-wrap gap-3">
            <x-admin-dashboard.button :href="route('admin.suppliers.warranty.proof', $claim)" variant="neutral" size="sm">Unduh screenshot asli (privat)</x-admin-dashboard.button>
            @if($claim->provider_chat_url)
                <x-admin-dashboard.button :href="$claim->provider_chat_url" variant="neutral" size="sm" rel="noreferrer noopener" target="_blank">Buka dispute chat supplier</x-admin-dashboard.button>
            @endif
            <form method="POST" action="{{ route('admin.suppliers.warranty.refresh', $claim) }}">
                @csrf
                <x-admin-dashboard.button type="submit" variant="neutral" size="sm">Baca ulang status supplier (GET)</x-admin-dashboard.button>
            </form>
        </div>
        @if(in_array($claim->state, ['submission_unknown', 'review'], true) && $claim->refund_deadline->isFuture())
            <form method="POST" action="{{ route('admin.suppliers.warranty.resubmit', $claim) }}" class="grid gap-2">
                @csrf
                <p class="text-sm leading-6 text-admin-muted">Kirim ulang bukti asli hanya jika GET authoritative terbaru tidak menemukan object refund. Aksi ini memakai bukti tersimpan yang sama, diantrikan ke worker upload, dan tidak pernah retry buta.</p>
                <x-admin-dashboard.button type="submit" variant="warning" size="sm">Resubmit bukti original</x-admin-dashboard.button>
            </form>
        @endif
        @if($claim->settlement_state === 'replacement_unknown')
            <form method="POST" action="{{ route('admin.suppliers.warranty.recover-replacement', $claim) }}" class="grid gap-3">
                @csrf
                <x-admin-dashboard.field-label>
                    Order ID supplier pengganti yang terverifikasi
                    <x-admin-dashboard.input name="supplier_order_id" maxlength="128" required autocomplete="off" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm">Pulihkan order pengganti yang sama (GET saja)</x-admin-dashboard.button>
            </form>
        @endif
        @if($claim->settlement_state === 'replacement_review' && $claim->replacementPurchase?->committed_at !== null)
            <form method="POST" action="{{ route('admin.suppliers.warranty.approve-acquired', $claim) }}" class="grid gap-3">
                @csrf
                <x-admin-dashboard.notice tone="warning">Akun pengganti sudah dibeli dengan biaya yang memerlukan review. Verifikasi biaya, deadline dan bukti pembelian; persetujuan tidak membeli lagi. Konflik account ID tetap ditolak.</x-admin-dashboard.notice>
                <x-admin-dashboard.field-label>
                    Saya menerima biaya aktual IDR
                    <x-admin-dashboard.input type="number" min="1" name="actual_cost_idr" required value="{{ intdiv($claim->replacementPurchase->actual_cost_micros, \App\Services\Suppliers\SupplierMoney::SCALE) }}" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm">Setujui delivery akun yang sudah diperoleh</x-admin-dashboard.button>
            </form>
        @endif
        @if($refund && $refund->state !== 'confirmed')
            <form method="POST" action="{{ route('admin.suppliers.warranty.refund', $refund) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <x-admin-dashboard.notice tone="neutral" class="sm:col-span-2">Catat transfer refund buyer yang benar-benar dilakukan. Form ini tidak mengirim uang.</x-admin-dashboard.notice>
                <x-admin-dashboard.field-label>
                    Nominal (IDR utuh / USDT micro-unit)
                    <x-admin-dashboard.input name="amount" type="number" min="1" required value="{{ $refund->amount }}" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Mata uang
                    <x-admin-dashboard.input name="currency" required readonly value="{{ $refund->currency }}" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Reference transfer
                    <x-admin-dashboard.input name="reference" maxlength="200" required autocomplete="off" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Hasil transfer
                    <x-admin-dashboard.select class="mt-1" name="result">
                        <option value="uncertain">Belum pasti / perlu recovery</option>
                        <option value="success">Transfer sukses dan terverifikasi</option>
                        <option value="failure">Transfer gagal</option>
                    </x-admin-dashboard.select>
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="sm:col-span-2">Catat bukti transfer</x-admin-dashboard.button>
            </form>
        @endif
    </x-admin-dashboard.card>
@endforeach
{{ $claims->links() }}
@foreach($missingRefunds as $refund)
    <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="mt-3 grid gap-3">
        @if($refund->supplier_fulfillment_id !== null)
            <h2 class="break-all text-lg font-semibold text-admin-ink">Refund sisa pesanan {{ $refund->order_id }}</h2>
            <p class="text-sm text-admin-ink">Quantity belum diperoleh: {{ $refund->missing_quantity }} · {{ $refund->amount }} {{ $refund->currency }} · {{ $refund->state }}</p>
        @else
            <h2 class="break-all text-lg font-semibold text-admin-ink">Refund sisa pembayaran pesanan {{ $refund->order_id }}</h2>
            <p class="text-sm text-admin-ink">Sisa tanpa klaim unit · {{ $refund->amount }} {{ $refund->currency }} · {{ $refund->state }}</p>
        @endif
        @if($refund->state !== 'confirmed')
            <form method="POST" action="{{ route('admin.suppliers.warranty.refund', $refund) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <x-admin-dashboard.notice tone="neutral" class="sm:col-span-2">Catat transfer refund yang benar-benar dilakukan. Form ini tidak mengirim uang.</x-admin-dashboard.notice>
                <x-admin-dashboard.field-label>
                    Nominal (IDR utuh / USDT micro-unit)
                    <x-admin-dashboard.input type="number" name="amount" min="1" value="{{ $refund->amount }}" required class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Mata uang
                    <x-admin-dashboard.input name="currency" value="{{ $refund->currency }}" readonly required class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Reference transfer
                    <x-admin-dashboard.input name="reference" maxlength="200" required autocomplete="off" class="mt-1" />
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.field-label>
                    Hasil
                    <x-admin-dashboard.select class="mt-1" name="result">
                        <option value="uncertain">Belum pasti</option>
                        <option value="success">Sukses terverifikasi</option>
                        <option value="failure">Gagal</option>
                    </x-admin-dashboard.select>
                </x-admin-dashboard.field-label>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="sm:col-span-2">Catat bukti transfer</x-admin-dashboard.button>
            </form>
        @endif
    </x-admin-dashboard.card>
@endforeach
{{ $missingRefunds->links() }}
@if($webhookEvents->isNotEmpty())
    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-3 grid gap-3">
        <h2 class="text-lg font-semibold text-admin-ink">Webhook supplier yang masih menunggu rekonsiliasi</h2>
        @foreach($webhookEvents as $event)
            <p class="break-all text-sm text-admin-ink">#{{ $event->id }} · Supplier #{{ $event->supplier_id }} · {{ $event->event_type }} · <x-admin-dashboard.badge tone="slate">{{ $event->state }}</x-admin-dashboard.badge> · {{ $event->last_error ?? 'menunggu worker' }} · {{ $event->next_attempt_at?->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}</p>
        @endforeach
        {{ $webhookEvents->links() }}
    </x-admin-dashboard.card>
@endif
@endsection
