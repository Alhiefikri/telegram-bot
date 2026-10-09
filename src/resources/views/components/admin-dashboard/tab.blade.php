@props([
    'tabId',
    'activeTab' => 'activeTab',
])

<button
    type="button"
    x-bind:class="{{ $activeTab }} === @js($tabId) ? 'bg-admin-accent-soft text-admin-accent' : 'bg-admin-surface text-admin-ink hover:bg-admin-surface-muted'"
    x-on:click="{{ $activeTab }} = @js($tabId)"
    {{ $attributes->class([
        'min-h-11 min-w-24 rounded-lg border border-admin-line px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper',
    ]) }}
>
    {{ $slot }}
</button>
