@props([
    'id' => 'admin-dashboard-modal',
    'show',
    'title',
    'titleExpression' => null,
    'description' => null,
    'closable' => true,
])

@php
    $titleId = $id.'-title';
@endphp

<div
    id="{{ $id }}"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    x-on:keydown.tab="trapModalFocus($event)"
    x-on:focusin.window="if ({{ $show }}) keepModalFocus($event, '{{ $id }}')"
    {{ $attributes->class([
        'relative rounded-[0.65rem] border-[4px] border-neo-ink bg-white p-4 text-neo-ink shadow-[7px_7px_0_0_#050505] sm:p-5',
    ]) }}
>
    <div class="flex items-start justify-between gap-4 pr-10">
        <div class="min-w-0">
            <h2 id="{{ $titleId }}" class="font-sans text-xl font-extrabold uppercase leading-none tracking-tight text-neo-ink sm:text-2xl" @if ($titleExpression) x-text="{{ $titleExpression }}" @endif>{{ $title }}</h2>

            @if ($description)
                <p class="mt-2 text-[11px] font-extrabold uppercase leading-5 tracking-wide text-neo-ink/65">{{ $description }}</p>
            @endif
        </div>
    </div>

    @if ($closable)
        <button
            type="button"
            class="absolute right-3 top-3 inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-white text-neo-ink shadow-[3px_3px_0_0_#050505] transition-transform hover:-translate-y-0.5 active:translate-x-[1px] active:translate-y-[1px]"
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
