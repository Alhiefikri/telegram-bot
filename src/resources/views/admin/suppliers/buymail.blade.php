@extends('admin.suppliers.layout')
@section('title', 'BuyMail · Deposit dan histori privat')
@section('content')
<x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="supplier-buymail-title">
    <p class="text-xs font-medium text-admin-muted">BuyMail</p>
    <h2 id="supplier-buymail-title" class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Deposit &amp; histori privat</h2>
    <p class="mt-2 text-sm leading-6 text-admin-muted">Top-up di halaman ini mengisi saldo supplier, bukan Saldo buyer. Kamu tetap membayar sendiri lewat halaman pembayaran supplier. Tidak ada auto-top-up atau penarikan rekening otomatis.</p>
</x-admin-dashboard.card>
<x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-3">
    <form method="POST" action="{{ route('admin.suppliers.buymail.deposit', $supplier['id']) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <x-admin-dashboard.field-label class="grid gap-2">
            Nominal IDR (Rp5.000–Rp5.000.000)
            <x-admin-dashboard.input type="number" name="amount" min="5000" max="5000000" required />
        </x-admin-dashboard.field-label>
        <x-admin-dashboard.button type="submit" variant="primary" size="sm">Buat satu instruksi top-up supplier</x-admin-dashboard.button>
        <p class="text-sm leading-6 text-admin-muted sm:col-span-2">Konfirmasi password diperlukan. Hanya satu deposit unpaid boleh aktif. Sinkron profil dahulu untuk memverifikasi identitas akun.</p>
    </form>
</x-admin-dashboard.card>
@foreach($deposits as $deposit)
    <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="mt-3 grid min-w-0 gap-3">
        <h2 class="break-all text-lg font-semibold text-admin-ink">{{ $deposit->external_id ?? 'Instruksi sedang dibuat' }} · IDR {{ number_format($deposit->amount, 0, ',', '.') }}</h2>
        <p class="text-sm text-admin-ink">Status: <x-admin-dashboard.badge tone="slate">{{ $deposit->state }}</x-admin-dashboard.badge> · Berlaku sampai {{ $deposit->expires_at?->timezone(config('app.timezone'))->format('Y-m-d H:i T') ?? 'belum diketahui' }}</p>
        @if($deposit->last_error)
            <x-admin-dashboard.notice tone="danger" class="break-all font-mono text-xs">Kode pemulihan: {{ $deposit->last_error }}</x-admin-dashboard.notice>
        @endif
        <div class="flex flex-wrap gap-3">
            @if($deposit->state === 'pending' && $deposit->payment_url)
                <x-admin-dashboard.button :href="$deposit->payment_url" variant="primary" size="sm" target="_blank" rel="noreferrer noopener">Buka pembayaran supplier</x-admin-dashboard.button>
            @endif
            <form method="POST" action="{{ route('admin.suppliers.buymail.deposit.refresh', $deposit) }}">
                @csrf
                <x-admin-dashboard.button type="submit" variant="neutral" size="sm">Pulihkan status instruksi yang sama</x-admin-dashboard.button>
            </form>
            @if($deposit->state === 'pending')
                <form method="POST" action="{{ route('admin.suppliers.buymail.deposit.cancel', $deposit) }}">
                    @csrf
                    <x-admin-dashboard.button type="submit" variant="warning" size="sm">Batalkan deposit unpaid</x-admin-dashboard.button>
                </form>
            @endif
        </div>
    </x-admin-dashboard.card>
@endforeach
{{ $deposits->links() }}
<form method="POST" action="{{ route('admin.suppliers.buymail.history', $supplier['id']) }}" class="mt-3">
    @csrf
    <x-admin-dashboard.button type="submit" variant="neutral" size="sm">Baca histori terakhir (GET saja; tidak membeli)</x-admin-dashboard.button>
</form>
@if($orders)
    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-3 grid gap-2">
        <h2 class="text-lg font-semibold text-admin-ink">20 order terakhir dari supplier</h2>
        @foreach($orders as $row)
            <p class="break-all text-sm text-admin-ink">{{ $row['id'] }} · Diminta {{ $row['requested'] }}, diterima {{ $row['delivered'] }} · Modal IDR {{ number_format($row['cost_idr'], 0, ',', '.') }}</p>
        @endforeach
    </x-admin-dashboard.card>
@endif
@if($remoteDeposits)
    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-3 grid gap-2">
        <h2 class="text-lg font-semibold text-admin-ink">20 deposit terakhir dari supplier</h2>
        @foreach($remoteDeposits as $row)
            <p class="break-all text-sm text-admin-ink">{{ $row['id'] }} · IDR {{ number_format($row['amount'], 0, ',', '.') }} · {{ $row['status'] }}</p>
        @endforeach
    </x-admin-dashboard.card>
@endif
@endsection
