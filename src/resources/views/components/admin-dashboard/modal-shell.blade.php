@props([
    'id' => 'admin-dashboard-modal',
    'title',
    'description' => null,
])

@php
    $titleId = $id.'-title';
@endphp

<div
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    {{ $attributes->class([
        'rounded-lg border border-slate-200 bg-white p-5 shadow-xl shadow-slate-950/10',
    ]) }}
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 id="{{ $titleId }}" class="font-display text-lg font-bold text-brand-navy">{{ $title }}</h2>

            @if ($description)
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</p>
            @endif
        </div>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
