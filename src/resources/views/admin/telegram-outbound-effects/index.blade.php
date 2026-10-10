<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Manual Delivery Review</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="penjualan" title="Manual Delivery Review">
        <a href="{{ route('admin.dashboard', ['tab' => 'penjualan']) }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke Pesanan</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
            <p class="text-xs font-medium text-admin-muted">Operational queue</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Manual Delivery Review</h1>
            <p class="mt-2 text-sm leading-6 text-admin-muted">Unknown dan Manual Delivery Review tidak dikirim ulang otomatis. Target Telegram dan metadata sensitif ditampilkan dalam bentuk tereduksi.</p>

            <div class="mt-5 space-y-3">
                @forelse ($reviews as $review)
                    <a href="{{ route('admin.telegram-outbound-effects.show', $review['effect']) }}" class="block rounded-lg border border-admin-line bg-admin-surface p-4 transition-colors hover:bg-admin-surface-muted">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-admin-ink">{{ $review['source_reference'] }}</p>
                                <p class="mt-1 text-sm font-medium text-admin-muted">{{ $review['effect_type'] }} · {{ $review['target'] }}</p>
                            </div>
                            <x-admin-dashboard.badge tone="{{ $review['state'] === 'manual_review' ? 'amber' : 'rose' }}">{{ $review['state'] }}</x-admin-dashboard.badge>
                        </div>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div><dt class="text-xs font-medium text-admin-muted">Attempts</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['attempts'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Sequence</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['sequence'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Error code</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['error_code'] ?? '—' }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Updated</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['updated_at'] }}</dd></div>
                        </dl>
                        @if ($review['duplicate_risk_acknowledged'])
                            <p class="mt-3 text-xs font-medium text-neo-warning-text">Duplicate-risk acknowledged for this explicit resend version.</p>
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
    </x-admin-dashboard.linked-shell>
</body>
</html>
