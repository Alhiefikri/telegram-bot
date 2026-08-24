<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Order {{ $order->invoice_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-bg text-neo-ink">
    <main class="mx-auto max-w-3xl p-4 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke Penjualan</a>

        @if (session('status'))
            <x-admin-dashboard.notice tone="success" class="mt-4 rounded-xl text-sm font-bold">{{ session('status') }}</x-admin-dashboard.notice>
        @endif

        @error('order')
            <x-admin-dashboard.notice tone="danger" class="mt-4 rounded-xl text-sm font-bold">{{ $message }}</x-admin-dashboard.notice>
        @enderror

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Detail Order</p>
                    <h1 class="mt-1 text-2xl font-black">{{ $order->invoice_number }}</h1>
                    <p class="mt-1 text-sm font-semibold text-brand-muted">{{ $customerLabel }} · {{ $createdAtLabel }}</p>
                </div>
                <div class="text-right text-sm font-bold">
                    <p>{{ $statusLabel }}</p>
                    <p class="mt-1">{{ $total }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none" class="rounded-xl border">
                    <p class="text-xs font-bold uppercase text-brand-muted">Item</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($itemLabels as $itemLabel)
                            <li>{{ $itemLabel }}</li>
                        @empty
                            <li>Belum ada item.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none" class="rounded-xl border">
                    <p class="text-xs font-bold uppercase text-brand-muted">Pembayaran</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($paymentLabels as $paymentLabel)
                            <li>{{ $paymentLabel }}</li>
                        @empty
                            <li>Belum ada payment.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>
            </div>

            @if ($paymentAttention)
                <x-admin-dashboard.notice :tone="$paymentAttention['tone']" class="mt-5 rounded-xl text-sm font-bold">
                    <span class="uppercase">{{ $paymentAttention['label'] }}:</span> {{ $paymentAttention['message'] }}
                </x-admin-dashboard.notice>
            @endif

            @if ($binanceReview)
                <x-admin-dashboard.notice tone="warning" class="mt-5 rounded-xl text-sm font-bold">
                    <p class="font-black">Perlu review pembayaran Binance</p>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div><dt class="text-brand-muted">Tagihan</dt><dd>{{ $binanceReview['expectedAmount'] }} USDT</dd></div>
                        <div><dt class="text-brand-muted">Diterima</dt><dd>{{ $binanceReview['receivedAmount'] }} {{ $binanceReview['currency'] }}</dd></div>
                        <div><dt class="text-brand-muted">Binance Order ID</dt><dd class="break-all">{{ $binanceReview['orderId'] }}</dd></div>
                        <div><dt class="text-brand-muted">Transaction ID</dt><dd class="break-all">{{ $binanceReview['transactionId'] }}</dd></div>
                        <div><dt class="text-brand-muted">Waktu transaksi</dt><dd>{{ $binanceReview['receivedAt'] }}</dd></div>
                    </dl>
                </x-admin-dashboard.notice>
            @endif

            @if ($bscReview)
                <x-admin-dashboard.notice tone="warning" class="mt-5 rounded-xl text-sm font-bold">
                    <p class="font-black">USDT BEP20 (BSC) verification</p>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div><dt class="text-brand-muted">Status</dt><dd>{{ $bscReview['status'] }}</dd></div>
                        <div><dt class="text-brand-muted">Expected amount</dt><dd>{{ $bscReview['expected_amount'] }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-brand-muted">Receiving address snapshot</dt><dd class="break-all">{{ $bscReview['receiver'] }}</dd></div>
                        <div><dt class="text-brand-muted">TX hash</dt><dd class="break-all">{{ $bscReview['tx_hash'] }}</dd></div>
                        <div><dt class="text-brand-muted">Confirmations</dt><dd>{{ $bscReview['confirmations'] }}</dd></div>
                        <div><dt class="text-brand-muted">From address</dt><dd class="break-all">{{ $bscReview['from_address'] }}</dd></div>
                    </dl>
                    @if ($bscReview['explorer'])
                        <a href="{{ $bscReview['explorer'] }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex font-black underline">Open BSC explorer</a>
                    @endif
                </x-admin-dashboard.notice>
            @endif

            @if ($canCompleteManual || $canRejectManual)
                <div class="mt-5 flex flex-wrap gap-3">
                    @if ($canCompleteManual)
                        <form method="POST" action="{{ route('admin.orders.complete-manual', $order) }}" onsubmit="return confirm('Setujui pembayaran ini dan kirim stok ke buyer? Pastikan transaksi sudah dicek.');">
                            @csrf
                            <x-admin-dashboard.button type="submit" variant="primary">Setujui pembayaran &amp; kirim stok</x-admin-dashboard.button>
                        </form>
                    @endif

                    @if ($canRejectManual)
                        <form method="POST" action="{{ route('admin.orders.reject-manual', $order) }}" onsubmit="return confirm('Tolak pembayaran ini dan lepas stok? Aksi ini tidak bisa dibatalkan.');">
                            @csrf
                            <x-admin-dashboard.button type="submit" variant="warning">Tolak pembayaran &amp; lepas stok</x-admin-dashboard.button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($canCancelRelease && ! $canRejectManual)
                <form method="POST" action="{{ route('admin.orders.cancel-release', $order) }}" class="mt-5" onsubmit="return confirm('Batalkan order dan lepas stok?')">
                    @csrf
                    <x-admin-dashboard.button type="submit" variant="warning">Batalkan & lepas stok</x-admin-dashboard.button>
                </form>
            @endif
        </x-admin-dashboard.card>

        @if ($paymentDetails !== [])
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">KlikQRIS observability</p>
                <div class="mt-3 space-y-3 text-sm font-semibold">
                    @foreach ($paymentDetails as $detail)
                        <div class="rounded-xl border-2 border-brand-border bg-[#f8fafc] p-3">
                            <dl class="grid gap-2 sm:grid-cols-2">
                                <div><dt class="text-brand-muted">Provider</dt><dd class="font-black">{{ $detail['provider'] }}</dd></div>
                                <div><dt class="text-brand-muted">Merchant ref</dt><dd class="font-black">{{ $detail['merchant_ref_short'] }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-brand-muted">Merchant ref hash</dt><dd class="break-all font-mono text-xs">{{ $detail['merchant_ref_hash'] }}</dd></div>
                                <div><dt class="text-brand-muted">Base amount</dt><dd>{{ $detail['base_amount'] }}</dd></div>
                                <div><dt class="text-brand-muted">Final amount</dt><dd>{{ $detail['final_amount'] }}</dd></div>
                                <div><dt class="text-brand-muted">provider_create_state</dt><dd>{{ $detail['provider_create_state'] }}</dd></div>
                                <div><dt class="text-brand-muted">Provider status</dt><dd>{{ $detail['provider_status'] }}</dd></div>
                                <div><dt class="text-brand-muted">Expiry</dt><dd>{{ $detail['expires_at'] }}</dd></div>
                                <div><dt class="text-brand-muted">Callback count</dt><dd>{{ $detail['callback_count'] }}</dd></div>
                                <div><dt class="text-brand-muted">Latest reconciliation</dt><dd>{{ $detail['latest_reconciliation_at'] }}</dd></div>
                            </dl>
                            @if ($detail['events'] !== [])
                                <ul class="mt-3 space-y-1 text-xs text-brand-muted">
                                    @foreach ($detail['events'] as $event)
                                        <li>{{ $event['type'] }} · @if ($event['condition'] !== '-'){{ $event['condition'] }} · @endif{{ $event['provider_status'] }} · received {{ $event['received_at'] }} · processed {{ $event['processed_at'] }} · deliveries {{ $event['received_count'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-admin-dashboard.card>
        @endif

        @if ($auditEntries !== [])
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Riwayat aksi</p>
                <div class="mt-3 space-y-2">
                    @foreach ($auditEntries as $entry)
                        <div class="rounded-xl border-2 border-brand-border bg-[#f8fafc] p-3 text-sm font-semibold">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-black text-neo-ink">{{ $entry['label'] }}</p>
                                <p class="text-xs font-bold text-brand-muted">{{ $entry['created_at'] }}</p>
                            </div>
                            <p class="mt-1 text-brand-muted">
                                Oleh {{ $entry['actor'] }}@if ($entry['ip_address']) · {{ $entry['ip_address'] }}@endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-admin-dashboard.card>
        @endif

        <x-admin-dashboard.sales-proof-card :proof="$salesProof" class="mt-4 rounded-2xl" />
    </main>
</body>
</html>
