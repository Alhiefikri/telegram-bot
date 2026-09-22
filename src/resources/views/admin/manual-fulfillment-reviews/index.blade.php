<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Manual Fulfillment Review</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
    <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-neo-xl border-[3px] border-neo-ink bg-white px-3 text-xs font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    <main class="mx-auto max-w-4xl p-4 pt-20 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke Penjualan</a>
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
    </main>
</body>
</html>
