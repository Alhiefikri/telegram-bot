@extends('admin.suppliers.layout')
@section('title', 'Klaim Gmail · Refund & penggantian')
@section('content')
<p>Refund supplier memulihkan modal ke saldo supplier. Refund buyer menggunakan harga retail snapshot dan proses terpisah. Keputusan supplier dapat reversed; uang buyer tidak ditarik kembali otomatis.</p>
<p>Klaim harus sampai ke supplier sebelum deadline. Screenshot asli hanya dibuka oleh Owner/Admin setelah konfirmasi password.</p>
@foreach($claims as $claim)
    @php
        $refund = $refunds->get($claim->id);
    @endphp
    <article class="grid min-w-0 gap-3 rounded-xl border bg-white p-4">
        <h2 class="break-all font-bold">{{ $claim->order->invoice_number }} · akun #{{ $claim->unit->unit_number }}</h2>
        <p>{{ $claim->unit->orderItem->product_name }} · Deadline {{ $claim->refund_deadline->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}</p>
        <p>Klaim: <code>{{ $claim->state }}</code> · Penyelesaian buyer: <code>{{ $claim->settlement_state }}</code> · Pilihan: {{ $claim->settlement_choice ?? 'belum memilih' }}</p>
        @if($claim->last_error || $claim->replacement_last_error)<p class="break-all">Kode pemulihan: {{ $claim->last_error ?? $claim->replacement_last_error }}</p>@endif
        <div class="flex flex-wrap gap-3">
            <a class="underline" href="{{ route('admin.suppliers.warranty.proof', $claim) }}">Unduh screenshot asli (privat)</a>
            @if($claim->provider_chat_url)<a class="underline" href="{{ $claim->provider_chat_url }}" rel="noreferrer noopener" target="_blank">Buka dispute chat supplier</a>@endif
            <form method="POST" action="{{ route('admin.suppliers.warranty.refresh', $claim) }}">@csrf<button class="rounded border px-3 py-2">Baca ulang status supplier (GET)</button></form>
        </div>
        @if($claim->settlement_state === 'replacement_unknown')
            <form method="POST" action="{{ route('admin.suppliers.warranty.recover-replacement', $claim) }}" class="grid gap-3">
                @csrf
                <label class="grid gap-1">Order ID supplier pengganti yang terverifikasi<input class="rounded border p-2" name="supplier_order_id" maxlength="128" required autocomplete="off"></label>
                <button class="rounded border px-4 py-3 font-bold">Pulihkan order pengganti yang sama (GET saja)</button>
            </form>
        @endif
        @if($claim->settlement_state === 'replacement_review' && $claim->replacementPurchase?->committed_at !== null)
            <form method="POST" action="{{ route('admin.suppliers.warranty.approve-acquired', $claim) }}" class="grid gap-3">
                @csrf
                <p>Akun pengganti sudah dibeli dengan biaya yang memerlukan review. Verifikasi biaya, deadline dan bukti pembelian; persetujuan tidak membeli lagi. Konflik account ID tetap ditolak.</p>
                <label class="grid gap-1">Saya menerima biaya aktual IDR<input class="rounded border p-2" type="number" min="1" name="actual_cost_idr" required value="{{ intdiv($claim->replacementPurchase->actual_cost_micros, \App\Services\Suppliers\SupplierMoney::SCALE) }}"></label>
                <button class="rounded border px-4 py-3 font-bold">Setujui delivery akun yang sudah diperoleh</button>
            </form>
        @endif
        @if($refund && $refund->state !== 'confirmed')
            <form method="POST" action="{{ route('admin.suppliers.warranty.refund', $refund) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <p class="font-bold sm:col-span-2">Catat transfer refund buyer yang benar-benar dilakukan. Form ini tidak mengirim uang.</p>
                <label class="grid gap-1">Nominal (IDR utuh / USDT micro-unit)<input class="rounded border p-2" name="amount" type="number" min="1" required value="{{ $refund->amount }}"></label>
                <label class="grid gap-1">Mata uang<input class="rounded border p-2" name="currency" required readonly value="{{ $refund->currency }}"></label>
                <label class="grid gap-1">Reference transfer<input class="rounded border p-2" name="reference" maxlength="200" required autocomplete="off"></label>
                <label class="grid gap-1">Hasil transfer<select class="rounded border p-2" name="result"><option value="uncertain">Belum pasti / perlu recovery</option><option value="success">Transfer sukses dan terverifikasi</option><option value="failure">Transfer gagal</option></select></label>
                <button class="rounded border px-4 py-3 font-bold sm:col-span-2">Catat bukti transfer</button>
            </form>
        @endif
    </article>
@endforeach
{{ $claims->links() }}
@foreach($missingRefunds as $refund)
    <article class="grid gap-3 rounded-xl border bg-white p-4">
        @if($refund->supplier_fulfillment_id !== null)
            <h2 class="break-all font-bold">Refund sisa pesanan {{ $refund->order_id }}</h2>
            <p>Quantity belum diperoleh: {{ $refund->missing_quantity }} · {{ $refund->amount }} {{ $refund->currency }} · {{ $refund->state }}</p>
        @else
            <h2 class="break-all font-bold">Refund sisa pembayaran pesanan {{ $refund->order_id }}</h2>
            <p>Sisa tanpa klaim unit · {{ $refund->amount }} {{ $refund->currency }} · {{ $refund->state }}</p>
        @endif
        @if($refund->state !== 'confirmed')
            <form method="POST" action="{{ route('admin.suppliers.warranty.refund', $refund) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <p class="sm:col-span-2">Catat transfer refund yang benar-benar dilakukan. Form ini tidak mengirim uang.</p>
                <label class="grid gap-1">Nominal (IDR utuh / USDT micro-unit)<input class="rounded border p-2" type="number" name="amount" min="1" value="{{ $refund->amount }}" required></label>
                <label class="grid gap-1">Mata uang<input class="rounded border p-2" name="currency" value="{{ $refund->currency }}" readonly required></label>
                <label class="grid gap-1">Reference transfer<input class="rounded border p-2" name="reference" maxlength="200" required autocomplete="off"></label>
                <label class="grid gap-1">Hasil<select class="rounded border p-2" name="result"><option value="uncertain">Belum pasti</option><option value="success">Sukses terverifikasi</option><option value="failure">Gagal</option></select></label>
                <button class="rounded border px-4 py-3 font-bold sm:col-span-2">Catat bukti transfer</button>
            </form>
        @endif
    </article>
@endforeach
{{ $missingRefunds->links() }}
@if($webhookEvents->isNotEmpty())
    <section class="grid gap-3 rounded-xl border bg-white p-4">
        <h2 class="font-bold">Webhook supplier yang masih menunggu rekonsiliasi</h2>
        @foreach($webhookEvents as $event)<p class="break-all">#{{ $event->id }} · Supplier #{{ $event->supplier_id }} · {{ $event->event_type }} · {{ $event->state }} · {{ $event->last_error ?? 'menunggu worker' }} · {{ $event->next_attempt_at?->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}</p>@endforeach
        {{ $webhookEvents->links() }}
    </section>
@endif
@endsection
