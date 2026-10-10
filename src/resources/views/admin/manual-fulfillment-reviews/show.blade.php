<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Review {{ $review->order->invoice_number }}</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="penjualan" title="Manual Fulfillment Review">
        <a href="{{ route('admin.manual-fulfillment-reviews.index') }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke antrean</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
            <p class="text-xs font-medium text-admin-muted">Manual Fulfillment Review</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">{{ $review->order->invoice_number }}</h1>
            <x-admin-dashboard.notice tone="warning" class="mt-4">Evidence pembayaran ini membutuhkan keputusan manual. Fulfillment tidak dijalankan otomatis.</x-admin-dashboard.notice>
            <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs font-medium text-admin-muted">Status review</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->state }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Alasan</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->reason }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Jumlah</dt><dd class="text-sm font-semibold text-admin-ink">{{ $amount }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Provider</dt><dd class="text-sm font-semibold text-admin-ink">{{ strtoupper($review->payment->provider) }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Referensi provider</dt><dd class="break-all font-mono text-xs">{{ $review->provider_reference ?: '-' }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Terkonfirmasi paid</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->payment->paid_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Dibuat</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->created_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Aktor resolusi</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->actor?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Waktu resolusi</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->resolved_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Alasan resolusi</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->resolution_reason ?: '-' }}</dd></div>
                @if ($review->refund_provider_reference)
                    <div class="sm:col-span-2"><dt class="text-xs font-medium text-admin-muted">Refund provider</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review->refund_provider_reference }} ({{ $review->refund_provider_result }})</dd></div>
                @endif
            </dl>
            @if ($handoffFulfillments->isNotEmpty())
                <section class="mt-5 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                    <h2 class="text-lg font-semibold text-admin-ink">Pengiriman pelanggan & pembelian supplier</h2>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Status paid membuktikan pembayaran saja. Handoff eksternal menyelesaikan pelanggan, tetapi hold dan pembelian supplier tetap menunggu bukti ekonomi.</p>
                    <div class="mt-3 grid gap-3">
                        @foreach ($handoffFulfillments as $fulfillment)
                            <article class="rounded-lg border border-admin-line bg-admin-surface p-3 text-sm">
                                <p><span class="font-semibold text-admin-ink">Fulfillment #{{ $fulfillment->id }}</span> · pelanggan: <span class="font-semibold text-admin-ink">{{ $fulfillment->state === 'delivered' ? 'selesai' : 'belum selesai' }}</span> · {{ $fulfillment->quantity }} unit</p>
                                @foreach ($fulfillment->manualHandoffs as $handoff)
                                    <p class="mt-2 text-admin-muted">Handoff {{ $handoff->quantity }} unit oleh {{ $handoff->actor?->name ?? 'operator dihapus' }} pada {{ $handoff->handed_off_at?->format('d M Y H:i') }}: {{ $handoff->note }}</p>
                                @endforeach
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </x-admin-dashboard.card>
        @can('resolve', $review)
            @if ($review->state === \App\Models\ManualFulfillmentReview::STATE_PENDING)
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
                    <h2 class="text-lg font-semibold text-admin-ink">Resolve review</h2>
                    @if ($errors->has('review'))<x-admin-dashboard.field-error>{{ $errors->first('review') }}</x-admin-dashboard.field-error>@endif
                    <form method="POST" action="{{ route('admin.manual-fulfillment-reviews.resolve', $review) }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="handoff_key" value="{{ $handoffKey }}">
                        <x-admin-dashboard.field-label>
                            Decision
                            <x-admin-dashboard.select name="action" class="mt-1">
                                <option value="fulfill">Fulfill</option>
                                @if ($review->reason === 'supplier_fulfillment')
                                    <option value="hand_off">Sudah diserahkan di luar bot</option>
                                @endif
                                <option value="refund">Refund</option>
                                <option value="reject">Reject</option>
                            </x-admin-dashboard.select>
                        </x-admin-dashboard.field-label>
                        @if ($review->reason === 'supplier_fulfillment')
                            <x-admin-dashboard.field-label>
                                Fulfillment yang diserahkan di luar bot
                                <x-admin-dashboard.select name="fulfillment_id" class="mt-1">
                                    <option value="">Pilih fulfillment</option>
                                    @foreach ($handoffFulfillments->where('state', 'review') as $fulfillment)
                                        <option value="{{ $fulfillment->id }}">#{{ $fulfillment->id }} · {{ $fulfillment->quantity }} unit</option>
                                    @endforeach
                                </x-admin-dashboard.select>
                            </x-admin-dashboard.field-label>
                            <x-admin-dashboard.field-label>
                                Jumlah unit diserahkan
                                <x-admin-dashboard.input name="handoff_quantity" type="number" min="1" step="1" class="mt-1" />
                            </x-admin-dashboard.field-label>
                        @endif
                        <x-admin-dashboard.field-label>
                            Auditable reason
                            <x-admin-dashboard.textarea name="reason" required maxlength="2000" class="mt-1" />
                            @if ($review->reason === 'supplier_fulfillment')
                                <small class="mt-1 block text-xs text-admin-muted">Untuk handoff luar bot, tulis waktu/keterangan singkat di catatan ini. Jangan masukkan password atau credential.</small>
                            @endif
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Refund provider reference (refund only)
                            <x-admin-dashboard.input name="refund_provider_reference" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.field-label>
                            Refund provider result (refund only)
                            <x-admin-dashboard.select name="refund_provider_result" class="mt-1">
                                <option value="success">Success</option>
                                <option value="failure">Failure</option>
                                <option value="uncertain">Uncertain</option>
                            </x-admin-dashboard.select>
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.button type="submit" variant="primary">Record resolution</x-admin-dashboard.button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan
    </x-admin-dashboard.linked-shell>
</body>
</html>
