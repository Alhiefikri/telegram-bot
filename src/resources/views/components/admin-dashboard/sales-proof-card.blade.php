@props([
    'proof',
    'compact' => false,
])

<x-admin-dashboard.card tone="slate" padding="sm" shadow="none" {{ $attributes->class(['rounded-lg border']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium text-admin-muted">Sales Proof Post</p>
            <p class="mt-1 {{ $compact ? 'text-xs' : 'text-sm' }} font-semibold text-admin-ink">{{ $proof['label'] }}</p>
        </div>
        <x-admin-dashboard.badge :tone="$proof['tone']">
            {{ $proof['status'] }}
        </x-admin-dashboard.badge>
    </div>

    @if ($proof['message_id'])
        <p class="mt-2 break-words {{ $compact ? 'text-xs' : 'text-sm' }} font-medium text-admin-ink">Telegram message ID: {{ $proof['message_id'] }}</p>
    @endif

    @if ($proof['last_error'])
        <x-admin-dashboard.notice tone="danger" class="mt-2 break-words {{ $compact ? 'text-xs' : 'text-sm' }} font-medium">Error: {{ $proof['last_error'] }}</x-admin-dashboard.notice>
    @endif

    @if ($proof['status'] !== 'none')
        <p class="mt-2 text-xs font-medium text-admin-muted">Attempts: {{ $proof['attempts'] }}</p>
    @endif

    @if ($proof['can_retry'])
        <form method="POST" action="{{ $proof['retry_url'] }}" class="mt-3">
            @csrf
            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="w-full justify-center">Retry Sales Proof Post</x-admin-dashboard.button>
        </form>
    @endif

    @if ($proof['review_url'])
        <a href="{{ $proof['review_url'] }}" class="mt-3 inline-flex text-xs font-medium text-admin-accent-strong underline-offset-4 hover:underline">Open Manual Delivery Review</a>
    @endif
</x-admin-dashboard.card>
