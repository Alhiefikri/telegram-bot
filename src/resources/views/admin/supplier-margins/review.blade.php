@extends('admin.supplier-margins.layout')

@section('margin-content')
    <x-admin-dashboard.card padding="compact" shadow="sm">
        <h2 class="text-lg font-semibold">Tinjau {{ count($changes) }} perubahan harga</h2>
        <p class="mt-2 text-sm text-admin-muted">Hanya harga reguler IDR yang berubah. Harga USD/USDT, tier grosir, dan isian khusus reseller tidak diubah. Reseller atau tier yang menggunakan harga dasar akan mengikuti harga baru. Notifikasi perubahan harga mengikuti aturan produk yang berlaku.</p>
        <ul class="my-4 divide-y divide-admin-line border-y border-admin-line">
            @foreach($changes as $change)
                <li class="grid gap-1 py-3 text-sm">
                    <p class="font-semibold">{{ $change['name'] }}</p>
                    <p><x-admin-dashboard.margin-amount :amount="$change['price']" /> → <strong><x-admin-dashboard.margin-amount :amount="$change['new_price']" /></strong></p>
                    <p class="text-admin-muted">
                        Estimasi selisih / unit: <x-admin-dashboard.margin-amount :amount="$change['delta']" signed /> →
                        <x-admin-dashboard.margin-amount :amount="$change['new_delta']" signed />
                        @if($change['new_reference']) · {{ $change['new_reference']['name'] }} @endif
                    </p>
                    @if($change['new_delta'] === null || $change['new_delta'] < $change['target'])
                        <p class="font-medium">{{ $change['new_delta'] === null ? 'Modal belum terverifikasi.' : 'Selisih baru masih di bawah target.' }}</p>
                    @endif
                    @if($change['new_fallback_delta'] !== null && $change['new_fallback_delta'] < $change['target'])
                        <p class="text-admin-muted">Cadangan terendah setelah perubahan: <x-admin-dashboard.margin-amount :amount="$change['new_fallback_delta']" signed /></p>
                    @endif
                </li>
            @endforeach
        </ul>
        <form method="POST" action="{{ route('admin.suppliers.margins.save', $query) }}" class="flex flex-wrap gap-3">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-admin-dashboard.button type="submit" variant="primary">Konfirmasi &amp; Simpan</x-admin-dashboard.button>
            <x-admin-dashboard.button :href="route('admin.suppliers.margins.index', $query)" variant="neutral">Batal</x-admin-dashboard.button>
        </form>
    </x-admin-dashboard.card>
@endsection
