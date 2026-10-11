@props([
    'id' => 'admin-dashboard-modal',
    'show',
    'title',
    'titleExpression' => null,
    'description' => null,
    'closable' => true,
    'size' => 'lg',
])

@php
    $titleId = $id.'-title';
    $width = match ($size) {
        'sm' => 'max-w-md',
        'md' => 'max-w-xl',
        'xl' => 'max-w-4xl',
        '2xl' => 'max-w-5xl',
        '3xl' => 'max-w-6xl',
        default => 'max-w-3xl',
    };
@endphp

<div
    id="{{ $id }}"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    x-on:keydown.tab="trapModalFocus($event)"
    x-on:focusin.window="if ({{ $show }}) keepModalFocus($event, '{{ $id }}')"
    {{ $attributes->class([
        'admin-modal-shell relative w-full rounded-xl border border-admin-line bg-admin-surface p-4 text-admin-ink shadow-admin-lg sm:p-5 '.$width,
    ]) }}
>
    <div class="flex items-start justify-between gap-4 pr-10">
        <div class="min-w-0">
            <h2 id="{{ $titleId }}" class="text-xl font-semibold leading-tight text-admin-ink sm:text-2xl" @if ($titleExpression) x-text="{{ $titleExpression }}" @endif>{{ $title }}</h2>

            @if ($description)
                <p class="mt-2 text-sm font-normal leading-5 text-admin-muted">{{ $description }}</p>
            @endif
        </div>
    </div>

    @if ($closable)
        <button
            type="button"
            class="absolute right-3 top-3 inline-flex h-11 w-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-admin-ink transition-colors hover:bg-admin-surface-muted focus-visible:outline-2 focus-visible:outline-admin-focus"
            aria-label="Tutup modal"
            x-on:click="$dispatch('admin-dashboard:close-modal', { modal: '{{ $id }}' })"
        >
            <span aria-hidden="true" class="text-lg font-black leading-none">×</span>
        </button>
    @endif

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
