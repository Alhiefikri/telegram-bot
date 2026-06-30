@props([
    'id' => 'admin-dashboard-modal',
    'title',
    'description' => null,
    'closable' => true,
])

@php
    $titleId = $id.'-title';
@endphp

<div
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    {{ $attributes->class([
        'relative rounded-[1.25rem] border-[3px] border-neo-ink bg-white p-5 text-neo-ink shadow-[6px_6px_0_0_#050505]',
    ]) }}
>
    <div class="flex items-start justify-between gap-4 pr-10">
        <div class="min-w-0">
            <h2 id="{{ $titleId }}" class="font-sans text-xl font-extrabold uppercase text-neo-ink">{{ $title }}</h2>

            @if ($description)
                <p class="mt-1 text-sm font-bold leading-6 text-neo-ink/60">{{ $description }}</p>
            @endif
        </div>
    </div>

    @if ($closable)
        <button
            type="button"
            class="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-[0.9rem] border-[3px] border-neo-ink bg-[#fef9c3] text-neo-ink shadow-[3px_3px_0_0_#050505] transition-transform hover:-translate-y-0.5 active:translate-x-[1px] active:translate-y-[1px]"
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
