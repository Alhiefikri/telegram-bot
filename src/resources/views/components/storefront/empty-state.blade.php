@props([
    'title',
    'body' => null,
    'icon' => null,
])

<x-storefront.panel {{ $attributes->class(['mx-auto max-w-md border-dashed text-center']) }} padding="lg">
    @if ($icon)
        <div class="mx-auto mb-4 grid h-11 w-11 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            {!! $icon !!}
        </div>
    @endif

    <p class="font-display text-sm font-black text-[var(--sf-ink)]">{{ $title }}</p>
    @if ($body)
        <p class="mt-2 text-xs font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $body }}</p>
    @endif

    {{ $slot }}
</x-storefront.panel>
