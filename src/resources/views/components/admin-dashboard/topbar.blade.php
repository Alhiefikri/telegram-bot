@props([
    'context' => null,
    'title' => null,
])

<div {{ $attributes->class(['flex min-h-16 flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6']) }}>
    @if (isset($menu))
        {{ $menu }}
    @endif
    <div class="min-w-0 flex-1">
        @if (isset($pageContext))
            {{ $pageContext }}
        @else
            @if ($context)
                <p class="truncate text-xs font-medium text-admin-muted">{{ $context }}</p>
            @endif
            @if ($title)
                <h1 class="truncate text-lg font-semibold text-admin-ink sm:text-xl">{{ $title }}</h1>
            @endif
        @endif
    </div>

    <div class="flex max-w-full flex-wrap items-center gap-2">
        @if (isset($status) && trim((string) $status) !== '')
            <div class="flex flex-wrap items-center gap-2">{{ $status }}</div>
        @endif
        @if (isset($actions) && trim((string) $actions) !== '')
            <div class="flex items-center gap-2">{{ $actions }}</div>
        @endif
        @if (isset($identity))
            <div class="hidden min-w-0 max-w-40 text-right text-xs text-admin-muted sm:block">{{ $identity }}</div>
        @endif
    </div>
</div>
