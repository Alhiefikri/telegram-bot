@extends('admin.suppliers.layout')
@section('title', 'BuyMail · Deposit dan histori privat')
@section('content')
<p>Top-up di halaman ini mengisi saldo supplier, bukan Saldo buyer. Kamu tetap membayar sendiri lewat halaman pembayaran supplier. Tidak ada auto-top-up atau penarikan rekening otomatis.</p>
<form method="POST" action="{{ route('admin.suppliers.buymail.deposit', $supplier['id']) }}" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2">
    @csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <label class="grid gap-2">Nominal IDR (Rp5.000–Rp5.000.000)<input class="rounded border p-2" type="number" name="amount" min="5000" max="5000000" required></label>
    <button class="rounded border px-4 py-3 font-bold" type="submit">Buat satu instruksi top-up supplier</button>
    <p class="text-sm sm:col-span-2">Konfirmasi password diperlukan. Hanya satu deposit unpaid boleh aktif. Sinkron profil dahulu untuk memverifikasi identitas akun.</p>
</form>
@foreach($deposits as $deposit)
    <article class="grid min-w-0 gap-3 rounded-xl border bg-white p-4">
        <h2 class="break-all font-bold">{{ $deposit->external_id ?? 'Instruksi sedang dibuat' }} · IDR {{ number_format($deposit->amount, 0, ',', '.') }}</h2>
        <p>Status: {{ $deposit->state }} · Berlaku sampai {{ $deposit->expires_at?->timezone(config('app.timezone'))->format('Y-m-d H:i T') ?? 'belum diketahui' }}</p>
        @if($deposit->last_error)<p class="break-all">Kode pemulihan: {{ $deposit->last_error }}</p>@endif
        <div class="flex flex-wrap gap-3">
            @if($deposit->state === 'pending' && $deposit->payment_url)<a class="font-bold underline" href="{{ $deposit->payment_url }}" target="_blank" rel="noreferrer noopener">Buka pembayaran supplier</a>@endif
            <form method="POST" action="{{ route('admin.suppliers.buymail.deposit.refresh', $deposit) }}">@csrf<button class="rounded border px-3 py-2">Pulihkan status instruksi yang sama</button></form>
            @if($deposit->state === 'pending')<form method="POST" action="{{ route('admin.suppliers.buymail.deposit.cancel', $deposit) }}">@csrf<button class="rounded border px-3 py-2">Batalkan deposit unpaid</button></form>@endif
        </div>
    </article>
@endforeach
{{ $deposits->links() }}
<form method="POST" action="{{ route('admin.suppliers.buymail.history', $supplier['id']) }}">@csrf<button class="rounded border px-4 py-3 font-bold">Baca histori terakhir (GET saja; tidak membeli)</button></form>
@if($orders)
    <section class="grid gap-2 rounded-xl border bg-white p-4"><h2 class="font-bold">20 order terakhir dari supplier</h2>
        @foreach($orders as $row)<p class="break-all">{{ $row['id'] }} · Diminta {{ $row['requested'] }}, diterima {{ $row['delivered'] }} · Modal IDR {{ number_format($row['cost_idr'], 0, ',', '.') }}</p>@endforeach
    </section>
@endif
@if($remoteDeposits)
    <section class="grid gap-2 rounded-xl border bg-white p-4"><h2 class="font-bold">20 deposit terakhir dari supplier</h2>
        @foreach($remoteDeposits as $row)<p class="break-all">{{ $row['id'] }} · IDR {{ number_format($row['amount'], 0, ',', '.') }} · {{ $row['status'] }}</p>@endforeach
    </section>
@endif
@endsection
