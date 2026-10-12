@extends('customer.partials.account-layout')

@section('account-title', 'Saldo saya')
@section('account-description', 'Ringkasan dan aktivitas saldo yang tercatat pada akun Anda.')

@section('account-content')
    <section class="account-panel account-balance-panel" aria-label="Ringkasan saldo">
        <div class="account-balance-currencies">
            <article>
                <h2>Store Balance · IDR</h2>
                @if($overview->storeBalance->isAvailable())
                    <p class="account-balance-value">{{ $idrBalanceDisplay }}</p>
                    <p class="account-note">Saldo tersedia berdasarkan ledger.</p>
                @else
                    <p class="account-balance-unavailable">Saldo sementara tidak tersedia.</p>
                    <p class="account-note">Data saldo sedang diperiksa; nominal tidak ditampilkan.</p>
                @endif
            </article>
            <article>
                <h2>Customer Balance · USDT</h2>
                @if($overview->usdtBalance->isAvailable())
                    <p class="account-balance-value">{{ $usdtBalanceDisplay }}</p>
                    @if($overview->usdtBalance->isFrozen())
                        <p class="account-notice">Saldo dibekukan sementara untuk pemeriksaan. Nominal tetap ditampilkan sebagai proyeksi tercatat.</p>
                    @else
                        <p class="account-note">Saldo tersedia berdasarkan ledger.</p>
                    @endif
                @else
                    <p class="account-balance-unavailable">Saldo sementara tidak tersedia.</p>
                    <p class="account-note">Data saldo sedang diperiksa; nominal tidak ditampilkan.</p>
                @endif
            </article>
        </div>
        <p class="account-readonly-note">Halaman ini hanya-baca. Top-up, pembayaran, dan perubahan saldo tidak tersedia melalui web.</p>
    </section>

    <section class="account-panel account-history" aria-labelledby="balance-history-heading">
        <div class="account-section-heading">
            <h2 id="balance-history-heading">Aktivitas saldo</h2>
            <nav class="account-filters" aria-label="Pilih mata uang saldo">
                @foreach(['IDR', 'USDT'] as $currencyTab)
                    <a href="{{ route('customer.balance.show', ['currency' => $currencyTab]) }}" @if($currency === $currencyTab) aria-current="page" @endif>{{ $currencyTab }}</a>
                @endforeach
            </nav>
        </div>
        <div class="account-activity-list">
            @forelse($activityRows as $row)
                <article class="account-activity-row">
                    <div>
                        <h3>{{ $row['kind'] }}</h3>
                        <p class="account-activity-reference">{{ $row['reference'] }}</p>
                        <p class="account-note">{{ $row['state'] }}</p>
                    </div>
                    <div class="account-activity-amount">
                        <strong>{{ $row['amount'] }}</strong>
                        <time>{{ $row['occurred_at'] }}</time>
                    </div>
                </article>
            @empty
                <div class="account-empty">
                    <h3>Belum ada aktivitas {{ $currency }}</h3>
                    <p>Aktivitas saldo yang tercatat pada akun ini akan tampil di sini.</p>
                </div>
            @endforelse
        </div>
        @if($pageLinks['newer'] || $pageLinks['older'])
            <nav class="account-history-pagination" aria-label="Paginasi riwayat saldo">
                @if($pageLinks['newer'])
                    <a href="{{ $pageLinks['newer'] }}" class="account-secondary-button">Aktivitas lebih baru</a>
                @endif
                @if($pageLinks['older'])
                    <a href="{{ $pageLinks['older'] }}" class="account-secondary-button">Aktivitas lebih lama</a>
                @endif
            </nav>
        @endif
    </section>
@endsection
