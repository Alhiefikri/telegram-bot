@props([
    'id',
    'name',
    'label',
    'accept' => 'image/jpeg,image/png,image/webp',
    'feedbackExpression' => null,
    'emptyFeedback' => 'Tidak ada file yang dipilih',
    'required' => false,
])

{{-- The native file input stays the real control: it is Tab-reachable, opens
     the native picker from Tab/Space/Enter, and keeps its name, accept and
     validation contract. It is visually replaced by the label below, so that
     visible label carries the focus and hover feedback for it. --}}
<div class="mt-2 flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-admin-line bg-admin-surface p-2">
    <input
        type="file"
        id="{{ $id }}"
        name="{{ $name }}"
        accept="{{ $accept }}"
        @required($required)
        {{ $attributes->class('peer sr-only') }}
    >
    {{-- The width floor is in CSS pixels on purpose: at 200% root text a
         rem-based floor grows past the available row width in the legacy
         modals and pushes the label outside its upload row. --}}
    <label
        for="{{ $id }}"
        class="inline-flex min-h-11 min-w-[44px] max-w-full shrink-0 cursor-pointer items-center justify-center rounded-md bg-admin-accent px-3 py-2 text-center text-xs font-semibold text-admin-accent-ink transition-[background-color] hover:bg-admin-accent-hover peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-admin-focus"
    >{{ $label }}</label>
    <span class="min-w-0 flex-1 truncate text-xs font-medium text-admin-muted" @if ($feedbackExpression) x-text="{{ $feedbackExpression }}" @endif>{{ $emptyFeedback }}</span>
</div>
