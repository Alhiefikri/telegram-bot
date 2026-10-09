@props([
    'context' => null,
    'title' => null,
])

<header {{ $attributes->class(['flex min-h-16 items-center justify-between gap-4 px-4 py-3 sm:px-6']) }}>
    <div class="min-w-0">
        @if ($context)
            <p class="truncate text-xs font-medium text-admin-muted">{{ $context }}</p>
        @endif
        @if ($title)
            <h1 class="truncate text-lg font-semibold text-admin-ink sm:text-xl">{{ $title }}</h1>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-2">
        @if (isset($status) && trim((string) $status) !== '')
            <div class="hidden items-center gap-2 sm:flex">{{ $status }}</div>
        @endif
        @if (isset($actions) && trim((string) $actions) !== '')
            <div class="flex items-center gap-2">{{ $actions }}</div>
        @endif
    </div>
</header>
