<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Manual Fulfillment Review</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="penjualan" title="Manual Fulfillment Review">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-semibold text-admin-accent-strong underline">← Kembali ke Pesanan</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Antrean operasional</p>
            <h1 class="mt-1 text-2xl font-black">Manual Fulfillment Review</h1>
            <p class="mt-2 text-sm font-semibold text-brand-muted">BSC evidence yang ambigu atau Late Payment tidak diselesaikan otomatis. Tidak ada stok atau kredensial yang dikirim dari antrean ini sampai ada keputusan Owner.</p>

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
                    <x-admin-dashboard.empty-state title="Tidak ada review yang menunggu keputusan." />
                @endforelse
            </div>

            @if ($reviews->hasPages())
                <div class="mt-5">{{ $reviews->links() }}</div>
            @endif
        </x-admin-dashboard.card>
    </x-admin-dashboard.linked-shell>
</body>
</html>
