<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Manual Delivery Review</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-bg text-neo-ink">
    <main class="mx-auto max-w-4xl p-4 sm:p-6">
        <a href="{{ route('admin.telegram-outbound-effects.index') }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke review queue</a>

        @if (session('status'))
            <x-admin-dashboard.notice tone="success" class="mt-4 rounded-xl text-sm font-bold">{{ session('status') }}</x-admin-dashboard.notice>
        @endif
        @if ($errors->has('effect'))
            <x-admin-dashboard.notice tone="danger" class="mt-4 rounded-xl text-sm font-bold">{{ $errors->first('effect') }}</x-admin-dashboard.notice>
        @endif

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Manual Delivery Review</p>
                    <h1 class="mt-1 text-2xl font-black">{{ $review['source_reference'] }}</h1>
                </div>
                <x-admin-dashboard.badge tone="{{ $review['state'] === 'manual_review' ? 'amber' : ($review['state'] === 'sent' ? 'emerald' : 'rose') }}">{{ $review['state'] }}</x-admin-dashboard.badge>
            </div>

            @if ($review['duplicate_risk_acknowledged'])
                <x-admin-dashboard.notice tone="warning" class="mt-4 rounded-xl text-sm font-bold">Duplicate-risk acknowledged. This is an explicit resend version and may create a duplicate Telegram delivery.</x-admin-dashboard.notice>
            @endif

            <dl class="mt-5 grid gap-3 text-sm font-semibold sm:grid-cols-2">
                <div><dt class="text-brand-muted">Effect type</dt><dd class="font-black">{{ $review['effect_type'] }}</dd></div>
                <div><dt class="text-brand-muted">Target</dt><dd class="font-black">{{ $review['target'] }}</dd></div>
                <div><dt class="text-brand-muted">Telegram method</dt><dd class="font-black">{{ $review['telegram_method'] }}</dd></div>
                <div><dt class="text-brand-muted">Queue</dt><dd class="font-black">{{ $review['queue'] }}</dd></div>
                <div><dt class="text-brand-muted">Locale / template</dt><dd class="font-black">{{ $review['locale'] }} / {{ $review['template_version'] }}</dd></div>
                <div><dt class="text-brand-muted">Attempts / sequence</dt><dd class="font-black">{{ $review['attempts'] }} / {{ $review['sequence'] }}</dd></div>
                <div><dt class="text-brand-muted">Error code</dt><dd class="font-black">{{ $review['error_code'] ?? '—' }}</dd></div>
                <div><dt class="text-brand-muted">Created</dt><dd>{{ $review['created_at'] }}</dd></div>
                <div><dt class="text-brand-muted">Unknown at</dt><dd>{{ $review['unknown_at'] }}</dd></div>
                <div><dt class="text-brand-muted">Manual review at</dt><dd>{{ $review['manual_review_at'] }}</dd></div>
                <div><dt class="text-brand-muted">Updated</dt><dd>{{ $review['updated_at'] }}</dd></div>
                <div><dt class="text-brand-muted">Sent at</dt><dd>{{ $review['sent_at'] }}</dd></div>
            </dl>

            @if ($review['metadata'] !== [])
                <div class="mt-5 rounded-xl border-2 border-brand-border bg-brand-bg p-3">
                    <p class="text-xs font-black uppercase tracking-wider text-brand-muted">Safe outcome metadata</p>
                    <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                        @foreach ($review['metadata'] as $key => $value)
                            <div><dt class="text-brand-muted">{{ $key }}</dt><dd class="font-black">{{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </x-admin-dashboard.card>

        @can('markSent', $effect)
            @if ($review['can_resolve'])
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                    <h2 class="text-lg font-black">Mark as sent after Telegram verification</h2>
                    <p class="mt-2 text-sm font-semibold text-brand-muted">This records the message identifier and audit metadata. It does not call Telegram or send another message.</p>
                    <form method="POST" action="{{ route('admin.telegram-outbound-effects.mark-sent', $effect) }}" class="mt-4 grid gap-3 sm:max-w-md">
                        @csrf
                        <label class="text-sm font-bold">Telegram message identifier
                            <input name="telegram_message_id" inputmode="numeric" pattern="0*[1-9][0-9]*" required maxlength="64" class="mt-1 block w-full rounded border-brand-border">
                        </label>
                        <button class="rounded bg-neo-ink px-4 py-2 font-black text-white">Mark as sent, no Telegram request</button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan

        @can('resend', $effect)
            @if ($review['can_resolve'])
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
                    <h2 class="text-lg font-black">Explicit resend</h2>
                    <p class="mt-2 text-sm font-semibold text-brand-muted">The old effect remains immutable. A new version is queued only after you acknowledge that Telegram may already have delivered the original.</p>
                    <form method="POST" action="{{ route('admin.telegram-outbound-effects.resend', $effect) }}" class="mt-4 grid gap-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm font-bold">
                            <input type="checkbox" name="confirm_duplicate_risk" value="1" required class="mt-1 rounded border-brand-border">
                            <span>I confirm the duplicate-risk and want a new resend effect.</span>
                        </label>
                        <button class="w-fit rounded border-2 border-neo-ink bg-white px-4 py-2 font-black text-neo-ink">Create explicit resend version</button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan
    </main>
</body>
</html>
