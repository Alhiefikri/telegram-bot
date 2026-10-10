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
        <a href="{{ route('admin.telegram-outbound-effects.index') }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">← Kembali ke review queue</a>
        @if (session('status'))
            <x-admin-dashboard.notice tone="success" class="mt-4">{{ session('status') }}</x-admin-dashboard.notice>
        @endif
        @if ($errors->has('effect'))
            <x-admin-dashboard.notice tone="danger" class="mt-4">{{ $errors->first('effect') }}</x-admin-dashboard.notice>
        @endif

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-admin-muted">Manual Delivery Review</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">{{ $review['source_reference'] }}</h1>
                </div>
                <x-admin-dashboard.badge tone="{{ $review['state'] === 'manual_review' ? 'amber' : ($review['state'] === 'sent' ? 'emerald' : 'rose') }}">{{ $review['state'] }}</x-admin-dashboard.badge>
            </div>

            @if ($review['duplicate_risk_acknowledged'])
                <x-admin-dashboard.notice tone="warning" class="mt-4">Duplicate-risk acknowledged. This is an explicit resend version and may create a duplicate Telegram delivery.</x-admin-dashboard.notice>
            @endif

            <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs font-medium text-admin-muted">Effect type</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['effect_type'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Target</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['target'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Telegram method</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['telegram_method'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Queue</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['queue'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Locale / template</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['locale'] }} / {{ $review['template_version'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Attempts / sequence</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['attempts'] }} / {{ $review['sequence'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Error code</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['error_code'] ?? '—' }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Created</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['created_at'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Unknown at</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['unknown_at'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Manual review at</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['manual_review_at'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Updated</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['updated_at'] }}</dd></div>
                <div><dt class="text-xs font-medium text-admin-muted">Sent at</dt><dd class="text-sm font-semibold text-admin-ink">{{ $review['sent_at'] }}</dd></div>
            </dl>

            @if ($review['metadata'] !== [])
                <div class="mt-5 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                    <p class="text-xs font-medium text-admin-muted">Safe outcome metadata</p>
                    <dl class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($review['metadata'] as $key => $value)
                            <div><dt class="text-xs font-medium text-admin-muted">{{ $key }}</dt><dd class="text-sm font-semibold text-admin-ink">{{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </x-admin-dashboard.card>

        @can('markSent', $effect)
            @if ($review['can_resolve'])
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
                    <h2 class="text-lg font-semibold text-admin-ink">Mark as sent after Telegram verification</h2>
                    <p class="mt-2 text-sm leading-6 text-admin-muted">This records the message identifier and audit metadata. It does not call Telegram or send another message.</p>
                    <form method="POST" action="{{ route('admin.telegram-outbound-effects.mark-sent', $effect) }}" class="mt-4 grid gap-3 sm:max-w-md">
                        @csrf
                        <x-admin-dashboard.field-label>
                            Telegram message identifier
                            <x-admin-dashboard.input name="telegram_message_id" inputmode="numeric" pattern="0*[1-9][0-9]*" required maxlength="64" class="mt-1" />
                        </x-admin-dashboard.field-label>
                        <x-admin-dashboard.button type="submit" variant="primary">Mark as sent, no Telegram request</x-admin-dashboard.button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan

        @can('resend', $effect)
            @if ($review['can_resolve'])
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4">
                    <h2 class="text-lg font-semibold text-admin-ink">Explicit resend</h2>
                    <p class="mt-2 text-sm leading-6 text-admin-muted">The old effect remains immutable. A new version is queued only after you acknowledge that Telegram may already have delivered the original.</p>
                    <form method="POST" action="{{ route('admin.telegram-outbound-effects.resend', $effect) }}" class="mt-4 grid gap-3">
                        @csrf
                        <label class="flex min-h-11 items-start gap-3 rounded-lg border border-admin-line bg-admin-surface px-4 py-3 text-sm font-medium text-admin-ink">
                            <input type="checkbox" name="confirm_duplicate_risk" value="1" required class="mt-0.5 h-5 w-5 rounded border-admin-line accent-admin-accent focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper">
                            <span>I confirm the duplicate-risk and want a new resend effect.</span>
                        </label>
                        <x-admin-dashboard.button type="submit" variant="neutral" class="w-fit">Create explicit resend version</x-admin-dashboard.button>
                    </form>
                </x-admin-dashboard.card>
            @endif
        @endcan
    </x-admin-dashboard.linked-shell>
</body>
</html>
