<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Order {{ $order->invoice_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" style="background: #f8fafc; color: #050505;">
    <main class="mx-auto max-w-3xl p-4 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-bold underline" style="color: #334155;">← Kembali ke Penjualan</a>

        <section class="mt-4 rounded-2xl bg-white p-5" style="border: 2px solid #050505; box-shadow: 4px 4px 0 #050505;">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider" style="color: #64748b;">Detail Order</p>
                    <h1 class="mt-1 text-2xl font-black">{{ $order->invoice_number }}</h1>
                    <p class="mt-1 text-sm font-semibold" style="color: #475569;">{{ $customerLabel }} · {{ $order->created_at?->format('d M Y H:i') }}</p>
                </div>
                <div class="text-right text-sm font-bold">
                    <p>{{ $order->order_status->value }} / {{ $order->payment_status->value }}</p>
                    <p class="mt-1">{{ $total }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl p-3" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                    <p class="text-xs font-bold uppercase" style="color: #64748b;">Item</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($order->orderItems as $item)
                            <li>{{ $item->product_name }} × {{ $item->quantity }}</li>
                        @empty
                            <li>Belum ada item.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="rounded-xl p-3" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                    <p class="text-xs font-bold uppercase" style="color: #64748b;">Pembayaran</p>
                    <ul class="mt-2 space-y-1 text-sm font-semibold">
                        @forelse ($order->payments as $payment)
                            <li>{{ $payment->method }} · {{ $payment->status->value }} · {{ $payment->paid_at?->format('d M Y H:i') ?? 'belum paid' }}</li>
                        @empty
                            <li>Belum ada payment.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </section>

        <section class="mt-4 rounded-2xl bg-white p-5" style="border: 2px solid #050505; box-shadow: 4px 4px 0 #050505;">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider" style="color: #64748b;">Sales Proof Post</p>
                    <h2 class="mt-1 text-xl font-black">{{ $salesProof['label'] }}</h2>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-black uppercase" style="background: {{ $salesProof['badge']['background'] }}; color: {{ $salesProof['badge']['color'] }};">{{ $salesProof['status'] }}</span>
            </div>

            @if ($salesProof['message_id'])
                <p class="mt-3 break-words text-sm font-semibold">Telegram message ID: {{ $salesProof['message_id'] }}</p>
            @endif

            @if ($salesProof['last_error'])
                <p class="mt-3 break-words rounded-xl p-3 text-sm font-semibold" style="background: #fee2e2; color: #991b1b;">Error: {{ $salesProof['last_error'] }}</p>
            @endif

            @if ($salesProof['status'] !== 'none')
                <p class="mt-3 text-xs font-bold uppercase" style="color: #64748b;">Attempts: {{ $salesProof['attempts'] }}</p>
            @endif

            @if ($salesProof['can_retry'])
                <form method="POST" action="{{ $salesProof['retry_url'] }}" class="mt-4">
                    @csrf
                    <button type="submit" class="rounded-xl px-4 py-3 text-sm font-black uppercase" style="background: #050505; color: #fff;">Retry Sales Proof Post</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
