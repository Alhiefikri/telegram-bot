<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manual Fulfillment Review</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-bg text-neo-ink">
    <main class="mx-auto max-w-4xl p-4 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke Penjualan</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Antrean operasional</p>
            <h1 class="mt-1 text-2xl font-black">Manual Fulfillment Review</h1>
            <p class="mt-2 text-sm font-semibold text-brand-muted">Late Payment tetap tercatat paid. Tidak ada stok atau kredensial yang dikirim otomatis dari antrean ini. Riwayat resolusi juga ditampilkan.</p>

            <div class="mt-5 space-y-3">
                @forelse ($reviews as $review)
                    <a href="{{ route('admin.manual-fulfillment-reviews.show', $review) }}" class="block rounded-xl border-2 border-brand-border bg-white p-4 hover:bg-brand-bg">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-black">{{ $review->order->invoice_number }}</p>
                                <p class="mt-1 text-sm font-semibold text-brand-muted">{{ strtoupper($review->payment->provider) }} · {{ $review->payment->method }} · {{ $review->currency }} {{ number_format($review->amount, 0, ',', '.') }}</p>
                            </div>
                            <x-admin-dashboard.badge tone="{{ $review->state === \App\Models\ManualFulfillmentReview::STATE_PENDING ? 'warning' : 'success' }}">{{ $review->state === \App\Models\ManualFulfillmentReview::STATE_PENDING ? 'Menunggu review' : $review->state }}</x-admin-dashboard.badge>
                        </div>
                    </a>
                @empty
                    <x-admin-dashboard.empty-state title="Tidak ada Late Payment yang menunggu review." />
                @endforelse
            </div>

            @if ($reviews->hasPages())
                <div class="mt-5">{{ $reviews->links() }}</div>
            @endif
        </x-admin-dashboard.card>
    </main>
</body>
</html>
