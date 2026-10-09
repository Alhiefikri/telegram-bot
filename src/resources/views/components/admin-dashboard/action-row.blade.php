@props([
    'href' => '#',
])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'flex min-h-11 items-center justify-between gap-3 rounded-lg border border-admin-line bg-admin-surface px-3 py-2 text-sm font-medium text-admin-ink transition-colors hover:bg-admin-surface-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper',
    ]) }}
>
    <span>{{ $slot }}</span>
    <span aria-hidden="true" class="text-admin-muted">→</span>
</a>
