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
                    <p class="mt-1 text-sm font-semibold text-[#475569]">{{ $customerLabel }} · {{ $order->created_at?->format('d M Y H:i') }}</p>
                </div>
                <div class="text-right text-sm font-bold">
                    <p>{{ $order->order_status->value }} / {{ $order->payment_status->value }}</p>
                    <p class="mt-1">{{ $total }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none" class="rounded-xl border">
                    <p class="text-xs font-bold uppercase text-brand-muted">Item</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($order->orderItems as $item)
                            <li>{{ $item->product_name }} × {{ $item->quantity }}</li>
                        @empty
                            <li>Belum ada item.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card padding="sm" tone="slate" shadow="none" class="rounded-xl border">
                    <p class="text-xs font-bold uppercase text-brand-muted">Pembayaran</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($order->payments as $payment)
                            <li>{{ $payment->method }} · {{ $payment->status->value }} · {{ $payment->paid_at?->format('d M Y H:i') ?? 'belum paid' }}</li>
                        @empty
                            <li>Belum ada payment.</li>
                        @endforelse
                    </ul>
                </x-admin-dashboard.card>
            </div>

            @if ($order->order_status === \App\Enums\OrderStatus::PendingPayment && $order->payment_status === \App\Enums\PaymentStatus::Pending)
                <form method="POST" action="{{ route('admin.orders.cancel-release', $order) }}" class="mt-5" onsubmit="return confirm('Batalkan order dan lepas stok?')">
                    @csrf
                    <x-admin-dashboard.button type="submit" variant="warning">Batalkan & lepas stok</x-admin-dashboard.button>
                </form>
            @endif
        </x-admin-dashboard.card>

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Sales Proof Post</p>
                    <h2 class="mt-1 text-xl font-black">{{ $salesProof['label'] }}</h2>
                </div>
                <x-admin-dashboard.badge :tone="$salesProof['tone']">{{ $salesProof['status'] }}</x-admin-dashboard.badge>
            </div>

            @if ($salesProof['message_id'])
                <p class="mt-3 break-words text-sm font-semibold">Telegram message ID: {{ $salesProof['message_id'] }}</p>
            @endif

            @if ($salesProof['last_error'])
                <x-admin-dashboard.notice tone="danger" class="mt-3 break-words rounded-xl text-sm font-semibold">Error: {{ $salesProof['last_error'] }}</x-admin-dashboard.notice>
            @endif

            @if ($salesProof['status'] !== 'none')
                <p class="mt-3 text-xs font-bold uppercase text-brand-muted">Attempts: {{ $salesProof['attempts'] }}</p>
            @endif

            @if ($salesProof['can_retry'])
                <form method="POST" action="{{ $salesProof['retry_url'] }}" class="mt-4">
                    @csrf
                    <x-admin-dashboard.button type="submit" variant="ink">Retry Sales Proof Post</x-admin-dashboard.button>
                </form>
            @endif
        </x-admin-dashboard.card>
    </main>
</body>
</html>
