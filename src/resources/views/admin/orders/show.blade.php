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

            @if ($canCancelRelease)
                <form method="POST" action="{{ route('admin.orders.cancel-release', $order) }}" class="mt-5" onsubmit="return confirm('Batalkan order dan lepas stok?')">
                    @csrf
                    <x-admin-dashboard.button type="submit" variant="warning">Batalkan & lepas stok</x-admin-dashboard.button>
                </form>
            @endif
        </x-admin-dashboard.card>

        <x-admin-dashboard.sales-proof-card :proof="$salesProof" class="mt-4 rounded-2xl" />
    </main>
</body>
</html>
