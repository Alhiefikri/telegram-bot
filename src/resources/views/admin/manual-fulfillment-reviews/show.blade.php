<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review {{ $review->order->invoice_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-bg text-neo-ink">
    <main class="mx-auto max-w-3xl p-4 sm:p-6">
        <a href="{{ route('admin.manual-fulfillment-reviews.index') }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke antrean</a>
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
        </x-admin-dashboard.card>
        @can('resolve', $review)
            @if ($review->state === \App\Models\ManualFulfillmentReview::STATE_PENDING)
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                    <h2 class="text-lg font-black">Resolve review</h2>
                    @if ($errors->has('review'))<p class="mt-2 text-sm font-bold text-red-700">{{ $errors->first('review') }}</p>@endif
                    <form method="POST" action="{{ route('admin.manual-fulfillment-reviews.resolve', $review) }}" class="mt-4 grid gap-3">
                        @csrf
                        <label class="text-sm font-bold">Decision
                            <select name="action" class="mt-1 block w-full rounded border-brand-border"><option value="fulfill">Fulfill</option><option value="refund">Refund</option><option value="reject">Reject</option></select>
                        </label>
                        <label class="text-sm font-bold">Auditable reason
                            <textarea name="reason" required maxlength="2000" class="mt-1 block w-full rounded border-brand-border"></textarea>
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
    </main>
</body>
</html>
