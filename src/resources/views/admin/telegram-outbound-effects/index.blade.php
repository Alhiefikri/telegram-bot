<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Manual Delivery Review</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
    <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-neo-xl border-[3px] border-neo-ink bg-white px-3 text-xs font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    <main class="mx-auto max-w-5xl p-4 pt-20 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke dashboard</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Operational queue</p>
            <h1 class="mt-1 text-2xl font-black">Manual Delivery Review</h1>
            <p class="mt-2 text-sm font-semibold text-brand-muted">Unknown dan Manual Delivery Review tidak dikirim ulang otomatis. Target Telegram dan metadata sensitif ditampilkan dalam bentuk tereduksi.</p>

            <div class="mt-5 space-y-3">
                @forelse ($reviews as $review)
                    <a href="{{ route('admin.telegram-outbound-effects.show', $review['effect']) }}" class="block rounded-xl border-2 border-brand-border bg-white p-4 hover:bg-brand-bg">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-black">{{ $review['source_reference'] }}</p>
                                <p class="mt-1 text-sm font-semibold text-brand-muted">{{ $review['effect_type'] }} · {{ $review['target'] }}</p>
                            </div>
                            <x-admin-dashboard.badge tone="{{ $review['state'] === 'manual_review' ? 'amber' : 'rose' }}">{{ $review['state'] }}</x-admin-dashboard.badge>
                        </div>
                        <dl class="mt-3 grid gap-2 text-xs font-semibold text-brand-muted sm:grid-cols-4">
                            <div><dt>Attempts</dt><dd class="font-black text-neo-ink">{{ $review['attempts'] }}</dd></div>
                            <div><dt>Sequence</dt><dd class="font-black text-neo-ink">{{ $review['sequence'] }}</dd></div>
                            <div><dt>Error code</dt><dd class="font-black text-neo-ink">{{ $review['error_code'] ?? '—' }}</dd></div>
                            <div><dt>Updated</dt><dd class="font-black text-neo-ink">{{ $review['updated_at'] }}</dd></div>
                        </dl>
                        @if ($review['duplicate_risk_acknowledged'])
                            <p class="mt-3 text-xs font-black text-amber-800">Duplicate-risk acknowledged for this explicit resend version.</p>
                        @endif
                    </a>
                @empty
                    <x-admin-dashboard.empty-state title="Tidak ada Telegram Outbound Effect yang menunggu review." />
                @endforelse
            </div>

            @if ($reviews->hasPages())
                <div class="mt-5">{{ $reviews->links() }}</div>
            @endif
        </x-admin-dashboard.card>
    </main>
</body>
</html>
