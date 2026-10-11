<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Detail Order {{ $order->invoice_number }}</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="penjualan" title="Detail order">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke Pesanan</a>

        @if (session('status'))
            <x-admin-dashboard.notice tone="success" class="mt-4">{{ session('status') }}</x-admin-dashboard.notice>
        @endif

        @error('order')
            <x-admin-dashboard.notice tone="danger" class="mt-4">{{ $message }}</x-admin-dashboard.notice>
        @enderror

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-admin-muted">Detail Order</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">{{ $order->invoice_number }}</h1>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">{{ $customerLabel }} · {{ $createdAtLabel }}</p>
                </div>
                <div class="text-right text-sm font-semibold text-admin-ink">
                    <p>Status order: {{ $orderStatusLabel }}</p>
                    <p class="mt-1">Status pembayaran: {{ $paymentStatusLabel }}</p>
                    <p class="mt-1">{{ $total }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none">
                    <p class="text-xs font-medium text-admin-muted">Item</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($itemLabels as $itemLabel)
                            <li>{{ $itemLabel }}</li>
                        @empty
                            <li>Belum ada item.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none">
                    <p class="text-xs font-medium text-admin-muted">Pembayaran</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($paymentLabels as $paymentLabel)
                            <li>{{ $paymentLabel }}</li>
                        @empty
                            <li>Belum ada payment.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>
            </div>

            <x-admin-dashboard.notice tone="neutral" class="mt-4 text-sm">
                Pembayaran, pengiriman pelanggan, dan pembelian supplier dicatat terpisah. Status Paid hanya mengonfirmasi pembayaran.
            </x-admin-dashboard.notice>
            @if ($hasPendingSupplierPurchase)
                <x-admin-dashboard.notice tone="warning" class="mt-3 text-sm">
                    Ada purchase supplier yang belum memiliki hasil ekonomi final. Lihat state dan hold per purchase sebelum mengambil keputusan berikutnya.
                </x-admin-dashboard.notice>
            @endif

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none">
                    <p class="text-xs font-medium text-admin-muted">Pengiriman pelanggan</p>
                    @if ($supplierFulfillments !== [])
                        <ul class="mt-2 space-y-3 text-sm">
                            @foreach ($supplierFulfillments as $fulfillment)
                                <li>
                                    <p>{{ $fulfillment['product_name'] }} · {{ $fulfillment['quantity'] }} unit</p>
                                    <p class="font-semibold text-admin-ink">{{ $fulfillment['delivery_status'] }}</p>
                                    @foreach ($fulfillment['handoffs'] as $handoff)
                                        <p class="mt-1 text-xs text-admin-muted">Handoff {{ $handoff['quantity'] }} unit oleh {{ $handoff['operator'] }} · {{ $handoff['at'] }} · {{ $handoff['note'] }}</p>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-sm font-semibold text-admin-ink">{{ $orderStatusLabel }}</p>
                    @endif
                </x-admin-dashboard.card>

                @if ($canViewSupplierOperations)
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none">
                    <p class="text-xs font-medium text-admin-muted">Pembelian supplier</p>
                    @if ($supplierFulfillments !== [])
                        <ul class="mt-2 space-y-3 text-sm">
                            @foreach ($supplierFulfillments as $fulfillment)
                                <li>
                                    <p>{{ $fulfillment['product_name'] }} · hold {{ $fulfillment['reserved_quantity'] }} unit / {{ $fulfillment['reserved_cost'] }}</p>
                                    @if ($fulfillment['last_wallet_observed_at'])
                                        <p class="text-xs text-admin-muted">Observasi wallet terakhir: {{ $fulfillment['last_wallet_observed_at'] }}</p>
                                    @endif
                                    @forelse ($fulfillment['purchases'] as $purchase)
                                        <p class="mt-1">#{{ $purchase['id'] }} · {{ $purchase['supplier_name'] }} · {{ $purchase['state'] }} · order {{ $purchase['supplier_order_id'] ?? 'belum diketahui' }}</p>
                                        <p class="text-xs text-admin-muted">{{ $purchase['quantity'] }} unit · biaya perkiraan {{ $purchase['expected_cost'] }} {{ $purchase['currency'] }} · aktual {{ $purchase['actual_cost'] ?? 'belum terbukti' }} · {{ $purchase['committed_at'] ?? 'belum diselesaikan' }}</p>
                                    @empty
                                        <p class="mt-1">Belum ada purchase dikirim.</p>
                                    @endforelse
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-sm font-semibold text-admin-ink">Tidak menggunakan purchase supplier.</p>
                    @endif
                </x-admin-dashboard.card>
                @endif
            </div>

            @if ($credentialMail !== null)
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none" class="mt-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-medium text-admin-muted">Email data akun</p>
                            <p class="mt-2 text-sm font-semibold text-admin-ink">
                                {{ $credentialMail['status_label'] }} · {{ $credentialMail['recipient'] ?? 'alamat pengiriman tidak tercatat' }}
                            </p>
                            <p class="mt-1 text-xs text-admin-muted">
                                Percobaan {{ $credentialMail['attempts'] }}
                                @if ($credentialMail['sent_at']) · terkirim {{ $credentialMail['sent_at'] }}@endif
                                @if ($credentialMail['last_attempt_at']) · percobaan terakhir {{ $credentialMail['last_attempt_at'] }}@endif
                                @if ($credentialMail['failed_at']) · gagal {{ $credentialMail['failed_at'] }}@endif
                                @if ($credentialMail['error_code']) · kode {{ $credentialMail['error_code'] }}@endif
                            </p>
                            <p class="mt-1 text-xs text-admin-muted">Alamat diambil dari email checkout pesanan ini; kirim ulang tidak pernah mengganti tujuan.</p>
                        </div>
                        @if ($credentialMail['can_resend'])
                            <form method="POST" action="{{ $credentialMail['resend_url'] }}">
                                @csrf
                                <x-admin-dashboard.button type="submit" variant="neutral">Kirim ulang email data akun</x-admin-dashboard.button>
                            </form>
                        @endif
                    </div>
                </x-admin-dashboard.card>
            @endif

            @if ($paymentAttention)
                <x-admin-dashboard.notice :tone="$paymentAttention['tone']" class="mt-5 text-sm">
                    <span class="font-semibold">{{ $paymentAttention['label'] }}:</span> {{ $paymentAttention['message'] }}
                </x-admin-dashboard.notice>
            @endif

            @if ($binanceReview)
                <x-admin-dashboard.notice tone="warning" class="mt-5 text-sm">
                    <p class="font-semibold text-admin-ink">Perlu review pembayaran Binance</p>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><dt class="text-xs font-medium text-admin-muted">Tagihan</dt><dd class="text-sm font-semibold text-admin-ink">{{ $binanceReview['expectedAmount'] }} USDT</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Diterima</dt><dd class="text-sm font-semibold text-admin-ink">{{ $binanceReview['receivedAmount'] }} {{ $binanceReview['currency'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Binance Order ID</dt><dd class="break-all font-mono text-xs">{{ $binanceReview['orderId'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Transaction ID</dt><dd class="break-all font-mono text-xs">{{ $binanceReview['transactionId'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Waktu transaksi</dt><dd class="text-sm font-semibold text-admin-ink">{{ $binanceReview['receivedAt'] }}</dd></div>
                    </dl>
                </x-admin-dashboard.notice>
            @endif

            @if ($bscReview)
                <x-admin-dashboard.notice tone="warning" class="mt-5 text-sm">
                    <p class="font-semibold text-admin-ink">USDT BEP20 (BSC) verification</p>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><dt class="text-xs font-medium text-admin-muted">Status</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['status'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Receipt</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['receipt_status'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Expected amount</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['expected_amount'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Observed token units</dt><dd class="break-all font-mono text-xs">{{ $bscReview['amount'] }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Token contract</dt><dd class="break-all font-mono text-xs">{{ $bscReview['contract'] }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Receiving address snapshot</dt><dd class="break-all font-mono text-xs">{{ $bscReview['receiver'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">TX hash</dt><dd class="break-all font-mono text-xs">{{ $bscReview['tx_hash'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Confirmations</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['confirmations'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Sender</dt><dd class="break-all font-mono text-xs">{{ $bscReview['sender'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Block</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['block'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Block timestamp</dt><dd class="text-sm font-semibold text-admin-ink">{{ $bscReview['block_timestamp'] }}</dd></div>
                    </dl>
                    @if ($bscReview['explorer'])
                        <a href="{{ $bscReview['explorer'] }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Open BSC explorer</a>
                    @endif
                    @if ($bscReview['manual_review_url'])
                        <a href="{{ $bscReview['manual_review_url'] }}" class="mt-3 ml-3 inline-flex text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Open manual fulfillment review</a>
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
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
                <p class="text-xs font-medium text-admin-muted">Observability payment</p>
                <div class="mt-3 space-y-3 text-sm">
                    @foreach ($paymentDetails as $detail)
                        <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                            <dl class="grid gap-3 sm:grid-cols-2">
                                <div><dt class="text-xs font-medium text-admin-muted">Provider</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['provider'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Merchant ref</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['merchant_ref_short'] }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Merchant ref hash</dt><dd class="break-all font-mono text-xs">{{ $detail['merchant_ref_hash'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Base amount</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['base_amount'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Final amount</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['final_amount'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">provider_create_state</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['provider_create_state'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Provider status</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['provider_status'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Expiry</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['expires_at'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Callback count</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['callback_count'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Latest reconciliation</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['latest_reconciliation_at'] }}</dd></div>
                                @if ($detail['provider'] === 'klikqris')
                                    <div><dt class="text-xs font-medium text-admin-muted">Checkout delivery state</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['checkout_delivery_state'] }}</dd></div>
                                    <div><dt class="text-xs font-medium text-admin-muted">Checkout delivery outcome</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['checkout_delivery_outcome'] }}</dd></div>
                                    <div><dt class="text-xs font-medium text-admin-muted">Checkout delivery method</dt><dd class="text-sm font-semibold text-admin-ink">{{ $detail['checkout_delivery_method'] }}</dd></div>
                                @endif
                            </dl>
                            @if ($detail['events'] !== [])
                                <p class="mt-3 text-xs font-medium text-admin-muted">Event terbaru ({{ $detail['event_total'] }} total, {{ count($detail['events']) }} ditampilkan)</p>
                                <ul class="mt-1 space-y-1 text-xs text-admin-muted">
                                    @foreach ($detail['events'] as $event)
                                        <li>{{ $event['type'] }} · @if ($event['condition'] !== '-'){{ $event['condition'] }} · @endif{{ $event['provider_status'] }} · received {{ $event['received_at'] }} · processed {{ $event['processed_at'] }} · deliveries {{ $event['received_count'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($detail['can_reconcile'])
                                <form method="POST" action="{{ $detail['reconcile_url'] }}" class="mt-3" onsubmit="return confirm('Cek ulang status payment ini ke provider? Order/Payment hanya berubah bila observasi provider terbukti.');">
                                    @csrf
                                    <x-admin-dashboard.button type="submit" variant="neutral" size="sm">Cek ulang status (rekonsiliasi)</x-admin-dashboard.button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-admin-dashboard.card>
        @endif

        @if ($auditEntries !== [])
            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
                <p class="text-xs font-medium text-admin-muted">Riwayat aksi</p>
                <div class="mt-3 space-y-2">
                    @foreach ($auditEntries as $entry)
                        <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold text-admin-ink">{{ $entry['label'] }}</p>
                                <p class="text-xs font-medium text-admin-muted">{{ $entry['created_at'] }}</p>
                            </div>
                            <p class="mt-1 text-admin-muted">
                                Oleh {{ $entry['actor'] }}@if ($entry['ip_address']) · {{ $entry['ip_address'] }}@endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-admin-dashboard.card>
        @endif

        <x-admin-dashboard.sales-proof-card :proof="$salesProof" class="mt-4" />
    </x-admin-dashboard.linked-shell>
</body>
</html>
