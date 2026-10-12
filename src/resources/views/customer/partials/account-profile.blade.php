@php($profile = $accountSummary['profile'])
<section class="account-profile account-panel" aria-labelledby="account-profile-name">
    <div class="account-identity">
        <div class="account-avatar" aria-hidden="true">{{ $profile['initial'] }}</div>
        <div class="account-identity-copy">
            <h2 id="account-profile-name">{{ $profile['name'] }}</h2>
            @if($profile['telegramUsername'])
                <p class="account-username">{{ '@'.ltrim($profile['telegramUsername'], '@') }}</p>
            @endif
            @if($profile['email'])
                <p class="account-email">{{ $profile['email'] }}</p>
            @endif
        </div>
    </div>
    @if($profile['joinedAt'])
        <p class="account-joined">Bergabung {{ $profile['joinedAt'] }}</p>
    @endif
    <div class="account-profile-balances">
        <div>
            <span>Saldo IDR</span>
            @if($accountSummary['idrBalanceDisplay'] !== null)
                <strong>{{ $accountSummary['idrBalanceDisplay'] }}</strong>
            @else
                <p class="account-balance-unavailable">Sementara tidak tersedia</p>
            @endif
        </div>
        <div>
            <span>Saldo USDT</span>
            @if($accountSummary['usdtBalanceDisplay'] !== null)
                <strong>{{ $accountSummary['usdtBalanceDisplay'] }}</strong>
                @if($accountSummary['usdtFrozen'])
                    <p class="account-note">Dibekukan untuk pemeriksaan</p>
                @endif
            @else
                <p class="account-balance-unavailable">Sementara tidak tersedia</p>
            @endif
        </div>
    </div>
    <a class="account-text-link" href="{{ route('customer.balance.show') }}">Lihat aktivitas saldo</a>
    <div class="account-order-summary">
        <div class="account-summary-heading">
            <h3>Pesanan Anda</h3>
            <strong>{{ $accountSummary['orderCounts']['all'] }}</strong>
        </div>
        <dl>
            <div><dt>Menunggu</dt><dd>{{ $accountSummary['orderCounts']['waiting'] }}</dd></div>
            <div><dt>Diproses</dt><dd>{{ $accountSummary['orderCounts']['processing'] }}</dd></div>
            <div><dt>Selesai</dt><dd>{{ $accountSummary['orderCounts']['completed'] }}</dd></div>
            <div><dt>Batal / kedaluwarsa</dt><dd>{{ $accountSummary['orderCounts']['issue'] }}</dd></div>
        </dl>
        <p class="account-note">Menunggu mencakup draft dan pesanan yang belum dibayar.</p>
    </div>
</section>
