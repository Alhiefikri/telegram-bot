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
        <a href="{{ route('admin.manual-fulfillment-reviews.index') }}" class="text-sm font-semibold text-admin-accent underline">← Kembali ke antrean</a>
        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Manual Fulfillment Review</p>
            <h1 class="mt-1 text-2xl font-black">{{ $review->order->invoice_number }}</h1>
            <x-admin-dashboard.notice tone="warning" class="mt-4 rounded-xl text-sm font-bold">Evidence pembayaran ini membutuhkan keputusan manual. Fulfillment tidak dijalankan otomatis.</x-admin-dashboard.notice>
            <dl class="mt-5 grid gap-3 text-sm font-semibold sm:grid-cols-2">
                <div><dt class="text-brand-muted">Status review</dt><dd class="font-black">{{ $review->state }}</dd></div>
                <div><dt class="text-brand-muted">Alasan</dt><dd class="font-black">{{ $review->reason }}</dd></div>
                <div><dt class="text-brand-muted">Jumlah</dt><dd class="font-black">{{ $amount }}</dd></div>
                <div><dt class="text-brand-muted">Provider</dt><dd class="font-black">{{ strtoupper($review->payment->provider) }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-brand-muted">Referensi provider</dt><dd class="break-all font-black">{{ $review->provider_reference ?: '-' }}</dd></div>
                <div><dt class="text-brand-muted">Terkonfirmasi paid</dt><dd>{{ $review->payment->paid_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div><dt class="text-brand-muted">Dibuat</dt><dd>{{ $review->created_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div><dt class="text-brand-muted">Aktor resolusi</dt><dd>{{ $review->actor?->name ?: '-' }}</dd></div>
                <div><dt class="text-brand-muted">Waktu resolusi</dt><dd>{{ $review->resolved_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-brand-muted">Alasan resolusi</dt><dd>{{ $review->resolution_reason ?: '-' }}</dd></div>
                @if ($review->refund_provider_reference)
                    <div class="sm:col-span-2"><dt class="text-brand-muted">Refund provider</dt><dd>{{ $review->refund_provider_reference }} ({{ $review->refund_provider_result }})</dd></div>
                @endif
            </dl>
            @if ($handoffFulfillments->isNotEmpty())
                <section class="mt-5 rounded-xl border border-brand-border p-4">
                    <h2 class="font-black">Pengiriman pelanggan & pembelian supplier</h2>
                    <p class="mt-1 text-sm text-brand-muted">Status paid membuktikan pembayaran saja. Handoff eksternal menyelesaikan pelanggan, tetapi hold dan pembelian supplier tetap menunggu bukti ekonomi.</p>
                    <div class="mt-3 grid gap-3">
                        @foreach ($handoffFulfillments as $fulfillment)
                            <article class="rounded-lg bg-brand-bg p-3 text-sm">
                                <p><span class="font-bold">Fulfillment #{{ $fulfillment->id }}</span> · pelanggan: <span class="font-bold">{{ $fulfillment->state === 'delivered' ? 'selesai' : 'belum selesai' }}</span> · {{ $fulfillment->quantity }} unit</p>
                                @foreach ($fulfillment->manualHandoffs as $handoff)
                                    <p class="mt-2 text-brand-muted">Handoff {{ $handoff->quantity }} unit oleh {{ $handoff->actor?->name ?? 'operator dihapus' }} pada {{ $handoff->handed_off_at?->format('d M Y H:i') }}: {{ $handoff->note }}</p>
                                @endforeach
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </x-admin-dashboard.card>
        @can('resolve', $review)
            @if ($review->state === \App\Models\ManualFulfillmentReview::STATE_PENDING)
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                    <h2 class="text-lg font-black">Resolve review</h2>
                    @if ($errors->has('review'))<p class="mt-2 text-sm font-bold text-red-700">{{ $errors->first('review') }}</p>@endif
                    <form method="POST" action="{{ route('admin.manual-fulfillment-reviews.resolve', $review) }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="handoff_key" value="{{ $handoffKey }}">
                        <label class="text-sm font-bold">Decision
                            <select name="action" class="mt-1 block w-full rounded border-brand-border"><option value="fulfill">Fulfill</option>@if ($review->reason === 'supplier_fulfillment')<option value="hand_off">Sudah diserahkan di luar bot</option>@endif<option value="refund">Refund</option><option value="reject">Reject</option></select>
                        </label>
                        @if ($review->reason === 'supplier_fulfillment')
                            <label class="text-sm font-bold">Fulfillment yang diserahkan di luar bot
                                <select name="fulfillment_id" class="mt-1 block w-full rounded border-brand-border"><option value="">Pilih fulfillment</option>@foreach ($handoffFulfillments->where('state', 'review') as $fulfillment)<option value="{{ $fulfillment->id }}">#{{ $fulfillment->id }} · {{ $fulfillment->quantity }} unit</option>@endforeach</select>
                            </label>
                            <label class="text-sm font-bold">Jumlah unit diserahkan
                                <input name="handoff_quantity" type="number" min="1" step="1" class="mt-1 block w-full rounded border-brand-border">
                            </label>
                        @endif
                        <label class="text-sm font-bold">Auditable reason
                            <textarea name="reason" required maxlength="2000" class="mt-1 block w-full rounded border-brand-border"></textarea>
                            @if ($review->reason === 'supplier_fulfillment')<small class="mt-1 block text-brand-muted">Untuk handoff luar bot, tulis waktu/keterangan singkat di catatan ini. Jangan masukkan password atau credential.</small>@endif
                        </label>
                        <label class="text-sm font-bold">Refund provider reference (refund only)
                            <input name="refund_provider_reference" class="mt-1 block w-full rounded border-brand-border">
                        </label>
                        <label class="text-sm font-bold">Refund provider result (refund only)
                            <select name="refund_provider_result" class="mt-1 block w-full rounded border-brand-border"><option value="success">Success</option><option value="failure">Failure</option><option value="uncertain">Uncertain</option></select>
                        </label>
                        <button class="rounded bg-neo-ink px-4 py-2 font-black text-white">Record resolution</button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan
    </x-admin-dashboard.linked-shell>
</body>
</html>
